<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ZKTeco ADMS / PUSH protocol receiver (no SDK required).
 *
 * The device is a plain HTTP client that drives the conversation:
 *   - Boot / pull state:  GET  /iclock/cdata?SN=<SERIAL>[&options...]
 *     Plain "OK" acks the poll; a queued server command is delivered as
 *     "C:<cmdId>:<command>" instead (device executes and comes back with
 *     ?ID=<cmdId> containing the result).
 *   - New punches:        POST /iclock/cdata?table=ATTLOG&SN=<SERIAL>
 *   - User registry:      POST /iclock/cdata?table=USERS&SN=<SERIAL>
 *     (requested via the "DATA UPDATE USERS" command)
 *
 * Responses must be plain text. The device retries forever unless it
 * receives exactly:
 *   - "OK"      for the handshake/poll request
 *   - "GBOK"    for a processed batch upload
 *   - "C:<id>:OK" for a command result poll
 */
class BioAttendanceController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Device handshake / state poll / command delivery.
     */
    public function handshake(Request $request)
    {
        $sn = (string) $request->query('SN', 'unknown');

        if (!$this->serialAllowed($sn)) {
            Log::warning('ZKTeco handshake rejected: unknown serial', ['sn' => $sn]);
            return response('ERR', 200)->header('Content-Type', 'text/plain');
        }

        // A device polling with ?ID=<n> is reporting the result of a command
        // we sent earlier (body carries the result, usually empty or "OK").
        if ($request->filled('ID')) {
            $this->registerCommandResult($sn, (string) $request->query('ID'), '');
            return response('C:' . $request->query('ID') . ':OK', 200)->header('Content-Type', 'text/plain');
        }

        Log::info('ZKTeco handshake', [
            'sn' => $sn,
            'ip' => $request->ip(),
        ]);

        // Deliver the next queued command instead of plain OK, if one exists.
        $pending = $this->nextQueuedCommand($sn);
        if ($pending !== null) {
            return response('C:' . $pending->id . ':' . $pending->command, 200)->header('Content-Type', 'text/plain');
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Batch intake: punch records, user registry uploads and command results.
     */
    public function store(Request $request)
    {
        $sn = (string) $request->query('SN', 'unknown');

        if (!$this->serialAllowed($sn)) {
            Log::warning('ZKTeco data rejected: unknown serial', ['sn' => $sn]);
            return response('GBERR', 200)->header('Content-Type', 'text/plain');
        }

        // Device reporting a command execution result.
        if ($request->filled('ID')) {
            $this->registerCommandResult($sn, (string) $request->query('ID'), $request->getContent());
            return response('C:' . $request->query('ID') . ':OK', 200)->header('Content-Type', 'text/plain');
        }

        $table = (string) $request->query('table', 'ATTLOG');
        $raw = $request->getContent();

        if ($table === 'ATTLOG') {
            if (!$this->storePunches($sn, $raw)) {
                // Not acking -> the device re-sends this batch, the UNIQUE
                // KEY dedups the second attempt.
                return response('GBERR', 200)->header('Content-Type', 'text/plain');
            }
        } elseif ($table === 'USERS' || $table === 'USERLOG') {
            $this->storeDeviceUsers($sn, $raw);
        } else {
            // Any other table (OPERLOG, ...) is intentionally ignored.
            Log::info('ZKTeco non-ATTLOG payload ignored', ['sn' => $sn, 'table' => $table, 'bytes' => strlen($raw)]);
        }

        return response('GBOK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Some firmwares alternate the poll endpoint; deliver queued commands
     * there too and return OK unconditionally so the device keeps its
     * normal pull schedule.
     */
    public function ping(Request $request)
    {
        if ($request->filled('ID')) {
            return response('C:' . $request->query('ID') . ':OK', 200)->header('Content-Type', 'text/plain');
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    // ------------------------------------------------------------------
    // Punch intake
    // ------------------------------------------------------------------

    private function storePunches(string $sn, string $raw): bool
    {
        $received = 0;
        $inserted = 0;
        $rows = [];

        // Records separated by "\n"; tolerate \r and stray empty lines.
        foreach (preg_split('/[\r\n]+/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $cols = explode("\t", $line);
            if (count($cols) < 2) {
                continue; // Malformed record — skip the garbage, keep the batch running
            }

            $userId = trim($cols[0]);
            $punchTime = trim($cols[1]);

            // Strict datetime gate: only well-formed device timestamps pass.
            if ($userId === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $punchTime)) {
                continue;
            }

            $received++;

            $rows[] = [
                'bio_user_id' => $userId,
                'punch_time' => $punchTime,
                'punch_type' => (int) ($cols[2] ?? 0),
                'verify_mode' => trim($cols[3] ?? '') ?: null,
                'work_code' => trim($cols[4] ?? '') ?: null,
                'machine_sn' => $sn,
                'created_at' => now(),
            ];
        }

        if ($rows === []) {
            Log::info('ZKTeco ATTLOG batch empty/unparseable', ['sn' => $sn, 'bytes' => strlen($raw)]);
            return true;
        }

        try {
            // Chunked INSERT IGNORE — duplicate (bio_user_id, punch_time)
            // pairs (re-connect re-transmission dumps) are dropped silently
            // by the UNIQUE KEY. One DB fault must not ACK the batch into a
            // retry loop of garbage.
            foreach (array_chunk($rows, 250) as $chunk) {
                $inserted += DB::table('bio_punches')->insertOrIgnore($chunk);
            }
        } catch (\Throwable $e) {
            // NOT acking -> the device re-sends this batch later, which the
            // unique key dedups. Log loudly, keep the system alive.
            Log::error('ZKTeco punch write failed; batch will retry', [
                'sn' => $sn,
                'received' => $received,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        Log::info('ZKTeco ATTLOG batch stored', [
            'sn' => $sn,
            'received' => $received,
            'inserted' => $inserted,
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Device user registry intake (DATA UPDATE USERS upload)
    // ------------------------------------------------------------------

    /**
     * Expected row shape (tab separated): PIN \t Name [\t ...extras]
     * Only the first two columns are meaningful to us; extras tolerated.
     */
    private function storeDeviceUsers(string $sn, string $raw): void
    {
        $count = 0;

        foreach (preg_split('/[\r\n]+/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }

            $cols = explode("\t", $line);
            $pin = trim($cols[0] ?? '');
            if ($pin === '' || !preg_match('/^[A-Za-z0-9_\-]{1,50}$/', $pin)) {
                continue; // Not a usable enrollment record
            }

            $name = trim($cols[1] ?? '');
            if (in_array(strtolower($name), ['none', 'null', ''], true)) {
                $name = null;
            }

            DB::table('bio_device_users')->updateOrInsert(
                ['machine_sn' => $sn, 'device_user_id' => $pin],
                [
                    'device_name' => $name !== null && $name !== '' ? mb_substr($name, 0, 100) : null,
                    'card_no' => isset($cols[4]) ? trim($cols[4]) ?: null : DB::raw('card_no'),
                    'privilege' => (int) ($cols[2] ?? 0),
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $count++;
        }

        Log::info('ZKTeco device user registry stored', ['sn' => $sn, 'users' => $count, 'bytes' => strlen($raw)]);
    }

    // ------------------------------------------------------------------
    // Command queue mechanics
    // ------------------------------------------------------------------

    private function nextQueuedCommand(string $sn)
    {
        $now = Carbon::now();

        // Re-queue commands that were sent but the device never reported
        // back on (firmware not presenting the result) within 10 minutes.
        DB::table('bio_device_commands')
            ->where('machine_sn', $sn)
            ->where('status', 'sent')
            ->where('sent_at', '<', $now->copy()->subMinutes(10))
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update(['status' => 'queued', 'sent_at' => null]);

        // Give up on stubborn commands after MAX_ATTEMPTS.
        DB::table('bio_device_commands')
            ->where('machine_sn', $sn)
            ->where('status', 'sent')
            ->where('sent_at', '<', $now->copy()->subMinutes(10))
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update(['status' => 'failed']);

        $command = DB::table('bio_device_commands')
            ->where('machine_sn', $sn)
            ->where('status', 'queued')
            ->orderBy('id')
            ->first();

        if ($command === null) {
            return null;
        }

        DB::table('bio_device_commands')
            ->where('id', $command->id)
            ->update([
                'status' => 'sent',
                'sent_at' => $now,
                'attempts' => $command->attempts + 1,
            ]);

        return $command;
    }

    private function registerCommandResult(string $sn, string $commandId, string $result): void
    {
        $body = strtoupper(trim($result));
        $ok = $body === '' || str_contains($body, 'OK') || str_contains($body, ':0');

        DB::table('bio_device_commands')
            ->where('id', (int) $commandId)
            ->where('machine_sn', $sn)
            ->update([
                'status' => $ok ? 'acked_ok' : 'acked_err',
                'acked_at' => now(),
                'result' => mb_substr($result, 0, 500) ?: null,
            ]);

        // When a name-update command ACKs successfully, flip the device row
        // from pending to synced.
        $command = DB::table('bio_device_commands')->where('id', (int) $commandId)->first();
        if ($ok && $command && str_starts_with($command->command, 'UPDATE USERINFO')) {
            if (preg_match('/PIN=([^\t]+)/', $command->command, $pinMatch)) {
                DB::table('bio_device_users')
                    ->where('machine_sn', $sn)
                    ->where('device_user_id', trim($pinMatch[1]))
                    ->where('name_sync_status', 'pending')
                    ->update([
                        'name_sync_status' => 'synced',
                        'device_name' => DB::raw('pending_name'),
                    ]);
            }
        }
    }

    private function serialAllowed(string $sn): bool
    {
        $allowed = (array) config('attendance.allowed_serials', []);
        return $allowed === [] || in_array($sn, $allowed, true);
    }
}
