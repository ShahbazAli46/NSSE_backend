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
 * The device is a plain HTTP client:
 *   - Boot / pull state:  GET  /iclock/cdata?SN=<SERIAL>[&options...]
 *   - New punches:        POST /iclock/cdata?table=ATTLOG&SN=<SERIAL>
 *     Body: raw text/plain, records separated by "\n", columns by "\t":
 *       USERID \t YYYY-MM-DD HH:MM:SS \t status \t verify \t workcode \t ...
 *
 * Responses must be plain text. The device retries forever unless it
 * receives exactly:
 *   - "OK"   for the handshake/poll request
 *   - "GBOK" for a processed ATTLOG batch
 */
class BioAttendanceController extends Controller
{
    /**
     * Device handshake / state poll.
     */
    public function handshake(Request $request)
    {
        $sn = (string) $request->query('SN', 'unknown');

        if (!$this->serialAllowed($sn)) {
            Log::warning('ZKTeco handshake rejected: unknown serial', ['sn' => $sn]);
            return response('ERR', 200)->header('Content-Type', 'text/plain');
        }

        Log::info('ZKTeco handshake', [
            'sn' => $sn,
            'ip' => $request->ip(),
            'query' => $request->query(),
        ]);

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Punch batch intake.
     */
    public function store(Request $request)
    {
        $sn = (string) $request->query('SN', 'unknown');

        if (!$this->serialAllowed($sn)) {
            Log::warning('ZKTeco data rejected: unknown serial', ['sn' => $sn]);
            return response('GBERR', 200)->header('Content-Type', 'text/plain');
        }

        $table = (string) $request->query('table', 'ATTLOG');
        $raw = $request->getContent();

        if ($table === 'ATTLOG') {
            $received = 0;
            $inserted = 0;
            $rows = [];

            // Records separated by "\n"; tolerate \r and stray empty lines.
            foreach (preg_split('/[\r\n]+/', trim($raw)) as $line) {
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
                return response('GBOK', 200)->header('Content-Type', 'text/plain');
            }

            try {
                // Chunked INSERT IGNORE — duplicate (bio_user_id, punch_time)
                // pairs (re-connect re-transmission dumps) are dropped silently
                // by the UNIQUE KEY. One DB fault must not ACK-ack the batch
                // into a retry loop of garbage.
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
                return response('GBERR', 200)->header('Content-Type', 'text/plain');
            }

            Log::info('ZKTeco ATTLOG batch stored', [
                'sn' => $sn,
                'received' => $received,
                'inserted' => $inserted,
            ]);
        } else {
            // Any other table (OPERLOG, USERLOG, ...) is intentionally ignored.
            Log::info('ZKTeco non-ATTLOG payload ignored', ['sn' => $sn, 'table' => $table, 'bytes' => strlen($raw)]);
        }

        return response('GBOK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Some firmwares alternate the poll endpoint; return OK unconditionally
     * so the device keeps its normal pull schedule.
     */
    public function ping(Request $request)
    {
        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    private function serialAllowed(string $sn): bool
    {
        $allowed = (array) config('attendance.allowed_serials', []);
        return $allowed === [] || in_array($sn, $allowed, true);
    }
}
