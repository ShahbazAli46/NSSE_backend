<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin API for the biometric machine management desk.
 *
 * Powers the "Bio Machines" page: per-machine enrolled user registry
 * (fetched from devices via the ADMS "DATA UPDATE USERS" command),
 * student matching, and two-way name synchronization
 * (app -> database + device via the UPDATE USERINFO command).
 */
class BiometricDeviceController extends Controller
{
    protected $authorizedRoles = ['hr', 'principal', 'office_admin', 'super_admin', 'admin'];

    protected function authorizeAccess(Request $request)
    {
        $role = $request->user()?->role;
        if (!in_array($role, $this->authorizedRoles)) {
            abort(403, 'Unauthorized access to biometric machine management.');
        }
    }

    /**
     * Every machine we have ever seen: SN, enrolled-user count, sync health.
     * Machines seen only through punches (no user registry pulled yet)
     * are listed too, so nothing stays hidden.
     */
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        $fromRegistry = DB::table('bio_device_users')
            ->selectRaw("
                machine_sn,
                COUNT(*) as users_count,
                SUM(CASE WHEN name_sync_status = 'pending' THEN 1 ELSE 0 END) as pending_names,
                MAX(last_seen_at) as last_registry_seen
            ")
            ->groupBy('machine_sn')
            ->get()
            ->keyBy('machine_sn');

        $fromPunches = DB::table('bio_punches')
            ->selectRaw('machine_sn, MAX(created_at) as last_punch_at')
            ->groupBy('machine_sn')
            ->get()
            ->keyBy('machine_sn');

        $serials = $fromRegistry->keys()->merge($fromPunches->keys())->unique()->values();

        $machines = [];
        foreach ($serials as $sn) {
            $reg = $fromRegistry->get($sn);
            $punch = $fromPunches->get($sn);

            $lastCommand = DB::table('bio_device_commands')
                ->where('machine_sn', $sn)
                ->orderByDesc('id')
                ->first();

            $machines[] = [
                'machine_sn' => $sn,
                'users_count' => (int) ($reg->users_count ?? 0),
                'pending_names' => (int) ($reg->pending_names ?? 0),
                'last_registry_seen' => $reg->last_registry_seen ?? null,
                'last_punch_at' => $punch->last_punch_at ?? null,
                'last_command_status' => $lastCommand->status ?? null,
                'last_command_at' => $lastCommand->acked_at ?? $lastCommand->sent_at ?? null,
            ];
        }

        // Most recently active machines first.
        usort($machines, fn ($a, $b) => strcmp($b['last_command_at'] ?? '', $a['last_command_at'] ?? ''));

        return response()->json(['machines' => $machines]);
    }

    /**
     * Enrolled users of one machine, joined with matched student records
     * (students.bio_user_id first, registration_no fallback).
     */
    public function users(Request $request, string $sn)
    {
        $this->authorizeAccess($request);

        $users = DB::table('bio_device_users')->where('machine_sn', $sn)
            ->orderBy('device_user_id')
            ->get();

        if ($users->isEmpty()) {
            return response()->json(['users' => [], 'machine_sn' => $sn]);
        }

        $pins = $users->pluck('device_user_id')->unique()->values();

        $students = DB::table('students')
            ->where('status', 'active')
            ->where(function ($q) use ($pins) {
                $q->whereIn('bio_user_id', $pins)->orWhereIn('registration_no', $pins);
            })
            ->get(['id', 'registration_no', 'student_name', 'class', 'section', 'bio_user_id'])
            ->keyBy(function ($student) {
                return $student->bio_user_id ?: $student->registration_no;
            });

        return response()->json([
            'machine_sn' => $sn,
            'users' => $users->map(function ($user) use ($students) {
                $student = $students->get($user->device_user_id);

                return [
                    'device_user_id' => $user->device_user_id,
                    'device_name' => $user->device_name,
                    'name_sync_status' => $user->name_sync_status,
                    'card_no' => $user->card_no,
                    'privilege' => (int) $user->privilege,
                    'last_seen_at' => $user->last_seen_at,
                    'student' => $student ? [
                        'id' => $student->id,
                        'registration_no' => $student->registration_no,
                        'student_name' => $student->student_name,
                        'class' => $student->class,
                        'section' => $student->section,
                    ] : null,
                ];
            }),
        ]);
    }

    /**
     * Queue the DATA UPDATE USERS command — the machine uploads its full
     * enrolled-user registry on its next poll (usually within seconds).
     */
    public function syncUsers(Request $request, string $sn)
    {
        $this->authorizeAccess($request);

        $inFlight = DB::table('bio_device_commands')
            ->where('machine_sn', $sn)
            ->whereIn('status', ['queued', 'sent'])
            ->where('command', 'DATA UPDATE USERS')
            ->exists();

        if ($inFlight) {
            return response()->json(['message' => 'A user-list pull is already queued for this machine.']);
        }

        DB::table('bio_device_commands')->insert([
            'machine_sn' => $sn,
            'command' => 'DATA UPDATE USERS',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Fetch queued. The machine uploads its enrolled users on its next poll (a few seconds).',
            'retries_note' => 'If the machine stays offline, the command is delivered automatically on reconnect.',
        ]);
    }

    /**
     * Save a corrected name: database first, device second.
     *
     * - Matched student       -> students.student_name updated + device sync queued
     * - Unmatched device user -> device name only (no student record to touch)
     */
    public function saveName(Request $request, string $sn, string $pin)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $name = trim($data['name']);
        $deviceUser = DB::table('bio_device_users')
            ->where('machine_sn', $sn)
            ->where('device_user_id', $pin)
            ->first();

        if (!$deviceUser) {
            return response()->json(['message' => 'This device user no longer exists. Re-pull the user list.'], 404);
        }

        $student = DB::table('students')
            ->where('status', 'active')
            ->where(function ($q) use ($pin) {
                $q->where('bio_user_id', $pin)->orWhere('registration_no', $pin);
            })
            ->first();

        $updatedStudent = false;
        if ($student) {
            DB::table('students')->where('id', $student->id)->update([
                'student_name' => $name,
                'updated_at' => now(),
            ]);
            $updatedStudent = true;
        }

        DB::table('bio_device_users')
            ->where('id', $deviceUser->id)
            ->update([
                'pending_name' => $name,
                'name_sync_status' => 'pending',
                'updated_at' => now(),
            ]);

        // Push-channel attribute format; tabs separate attributes.
        DB::table('bio_device_commands')->insert([
            'machine_sn' => $sn,
            'command' => 'UPDATE USERINFO PIN=' . $pin . "\tName=" . $name,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Name saved. Database updated'
                . ($updatedStudent ? ' (student record too)' : ' (device-only entry)')
                . ' — machine applies it on its next poll.',
            'student_updated' => $updatedStudent,
        ]);
    }
}
