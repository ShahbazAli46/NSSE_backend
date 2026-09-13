<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\StaffAttendance;
use App\Models\StaffMember;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffAttendanceController extends Controller
{
    protected $authorizedRoles = ['hr', 'principal', 'super_admin', 'admin', 'chairman'];

    /**
     * Get Daily Staff Attendance Roster for a specific date.
     */
    public function getDaily(Request $request)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, array_merge($this->authorizedRoles, ['accountant', 'hr_accountant', 'teacher']))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $date = $request->input('date', Carbon::today()->toDateString());
        $type = $request->input('type', 'all'); // 'all', 'teaching', 'non_teaching'
        $search = trim($request->input('search', ''));

        $roster = [];

        // 1. Load Teaching Staff
        if (in_array($type, ['all', 'teaching'])) {
            $teacherQuery = Teacher::query()->where('status', '!=', 'inactive');
            if ($search) {
                $teacherQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('qualification', 'like', "%{$search}%")
                      ->orWhere('cnic', 'like', "%{$search}%")
                      ->orWhere('contact_number', 'like', "%{$search}%");
                });
            }
            $teachers = $teacherQuery->orderBy('name', 'asc')->get();

            foreach ($teachers as $teacher) {
                $roster[] = [
                    'key' => "teacher_{$teacher->id}",
                    'staff_type' => 'teacher',
                    'staff_id' => $teacher->id,
                    'user_id' => $teacher->user_id,
                    'name' => $teacher->name,
                    'category_label' => 'Teaching Faculty',
                    'designation' => $teacher->qualification ? "Faculty ({$teacher->qualification})" : 'Teaching Faculty',
                    'cnic' => $teacher->cnic,
                    'contact_number' => $teacher->contact_number,
                    'profile_picture_url' => $teacher->profile_picture_url,
                    'staff_status' => $teacher->status,
                ];
            }
        }

        // 2. Load Non-Teaching Staff
        if (in_array($type, ['all', 'non_teaching'])) {
            $staffQuery = StaffMember::query()->where('status', '!=', 'inactive');
            if ($search) {
                $staffQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('designation', 'like', "%{$search}%")
                      ->orWhere('cnic', 'like', "%{$search}%")
                      ->orWhere('contact_number', 'like', "%{$search}%");
                });
            }
            $staffMembers = $staffQuery->orderBy('name', 'asc')->get();

            foreach ($staffMembers as $staff) {
                $roster[] = [
                    'key' => "non_teaching_{$staff->id}",
                    'staff_type' => 'non_teaching',
                    'staff_id' => $staff->id,
                    'user_id' => $staff->user_id,
                    'name' => $staff->name,
                    'category_label' => 'Non-Teaching Staff',
                    'designation' => $staff->designation ?: 'Staff Member',
                    'cnic' => $staff->cnic,
                    'contact_number' => $staff->contact_number,
                    'profile_picture_url' => $staff->profile_picture_url,
                    'staff_status' => $staff->status,
                ];
            }
        }

        // 3. Fetch Existing Attendance Records for this Date
        $attendances = StaffAttendance::where('date', $date)->get()->keyBy(function ($item) {
            return "{$item->staff_type}_{$item->staff_id}";
        });

        // 4. Fetch Approved Leave Requests covering this date (Locked by Principal)
        $approvedLeaves = LeaveRequest::where('status', 'approved')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->get()
            ->keyBy(fn($l) => "{$l->staff_type}_{$l->staff_id}");

        // 5. Merge attendance & leave status into roster
        $presentCount = 0;
        $lateCount = 0;
        $absentCount = 0;
        $onLeaveCount = 0;
        $markedCount = 0;

        foreach ($roster as &$item) {
            $att = $attendances->get($item['key']);
            $leave = $approvedLeaves->get($item['key']);

            $item['is_leave_locked'] = !is_null($leave);
            $item['leave_info'] = $leave ? [
                'id' => $leave->id,
                'leave_type' => $leave->leave_type,
                'reason' => $leave->reason,
                'approver_name' => $leave->approver_name,
                'start_date' => $leave->start_date->toDateString(),
                'end_date' => $leave->end_date->toDateString(),
                'total_days' => $leave->total_days,
            ] : null;

            if ($att) {
                $item['attendance'] = [
                    'id' => $att->id,
                    'status' => $att->status,
                    'check_in_time' => $att->check_in_time,
                    'check_out_time' => $att->check_out_time,
                    'remarks' => $att->remarks,
                    'marked_by' => $att->marked_by,
                ];
                $markedCount++;
                if ($att->status === 'present') $presentCount++;
                elseif ($att->status === 'late') $lateCount++;
                elseif ($att->status === 'absent') $absentCount++;
                elseif ($att->status === 'on_leave') $onLeaveCount++;
            } elseif ($leave) {
                // If attendance row not yet physically inserted, treat as on_leave
                $item['attendance'] = [
                    'id' => null,
                    'status' => 'on_leave',
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'remarks' => "Official Leave: " . ucfirst($leave->leave_type) . " ({$leave->reason}) - Approved by {$leave->approver_name}",
                    'marked_by' => $leave->approved_by,
                ];
                $markedCount++;
                $onLeaveCount++;
            } else {
                $item['attendance'] = null;
            }
        }
        unset($item);

        return response()->json([
            'date' => $date,
            'roster' => $roster,
            'stats' => [
                'total_staff' => count($roster),
                'marked_count' => $markedCount,
                'unmarked_count' => count($roster) - $markedCount,
                'present_count' => $presentCount,
                'late_count' => $lateCount,
                'absent_count' => $absentCount,
                'on_leave_count' => $onLeaveCount,
            ],
        ]);
    }

    /**
     * Mark Attendance for a single staff member.
     */
    public function mark(Request $request)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, $this->authorizedRoles)) {
            return response()->json(['message' => 'Unauthorized. Only HR, Principal, or Admin can mark attendance.'], 403);
        }

        $request->validate([
            'staff_type' => 'required|in:teacher,non_teaching',
            'staff_id' => 'required|integer',
            'date' => 'required|date',
            'status' => 'required|in:present,late,absent,on_leave',
            'check_in_time' => 'nullable|string|max:20',
            'check_out_time' => 'nullable|string|max:20',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // Guard Check: Cannot modify attendance if there is a Principal-approved leave on this date
        $hasApprovedLeave = LeaveRequest::where('status', 'approved')
            ->where('staff_type', $request->staff_type)
            ->where('staff_id', $request->staff_id)
            ->where('start_date', '<=', $request->date)
            ->where('end_date', '>=', $request->date)
            ->first();

        if ($hasApprovedLeave) {
            return response()->json([
                'message' => "Attendance cannot be modified. {$hasApprovedLeave->staff_name} is on Principal-approved official leave on {$request->date} (" . ucfirst($hasApprovedLeave->leave_type) . " Leave: {$hasApprovedLeave->reason})."
            ], 422);
        }

        $userId = null;
        if ($request->staff_type === 'teacher') {
            $teacher = Teacher::find($request->staff_id);
            if ($teacher) $userId = $teacher->user_id;
        } else {
            $staff = StaffMember::find($request->staff_id);
            if ($staff) $userId = $staff->user_id;
        }

        $attendance = StaffAttendance::updateOrCreate(
            [
                'staff_type' => $request->staff_type,
                'staff_id' => $request->staff_id,
                'date' => $request->date,
            ],
            [
                'user_id' => $userId,
                'status' => $request->status,
                'check_in_time' => $request->check_in_time ?: ($request->status === 'present' || $request->status === 'late' ? Carbon::now()->format('h:i A') : null),
                'check_out_time' => $request->check_out_time,
                'remarks' => $request->remarks,
                'marked_by' => $currentUser->id,
            ]
        );

        return response()->json([
            'message' => 'Attendance recorded successfully.',
            'data' => $attendance,
        ]);
    }

    /**
     * Mark All Unrecorded Staff as Present for a given date.
     */
    public function markAllPresent(Request $request)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, $this->authorizedRoles)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $date = $request->input('date', Carbon::today()->toDateString());
        $type = $request->input('type', 'all');
        $checkInTime = Carbon::now()->format('h:i A');

        $count = 0;

        // Fetch staff with approved leaves on this date
        $leaveStaffKeys = LeaveRequest::where('status', 'approved')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->get()
            ->map(fn($l) => "{$l->staff_type}_{$l->staff_id}")
            ->toArray();

        DB::beginTransaction();
        try {
            // Teachers
            if (in_array($type, ['all', 'teaching'])) {
                $teachers = Teacher::where('status', 'active')->get();
                foreach ($teachers as $teacher) {
                    if (in_array("teacher_{$teacher->id}", $leaveStaffKeys)) {
                        continue; // Skip staff on approved leave
                    }

                    $exists = StaffAttendance::where('staff_type', 'teacher')
                        ->where('staff_id', $teacher->id)
                        ->where('date', $date)
                        ->exists();

                    if (!$exists) {
                        StaffAttendance::create([
                            'staff_type' => 'teacher',
                            'staff_id' => $teacher->id,
                            'user_id' => $teacher->user_id,
                            'date' => $date,
                            'status' => 'present',
                            'check_in_time' => $checkInTime,
                            'marked_by' => $currentUser->id,
                        ]);
                        $count++;
                    }
                }
            }

            // Non-Teaching Staff
            if (in_array($type, ['all', 'non_teaching'])) {
                $staffMembers = StaffMember::where('status', 'active')->get();
                foreach ($staffMembers as $staff) {
                    if (in_array("non_teaching_{$staff->id}", $leaveStaffKeys)) {
                        continue; // Skip staff on approved leave
                    }

                    $exists = StaffAttendance::where('staff_type', 'non_teaching')
                        ->where('staff_id', $staff->id)
                        ->where('date', $date)
                        ->exists();

                    if (!$exists) {
                        StaffAttendance::create([
                            'staff_type' => 'non_teaching',
                            'staff_id' => $staff->id,
                            'user_id' => $staff->user_id,
                            'date' => $date,
                            'status' => 'present',
                            'check_in_time' => $checkInTime,
                            'marked_by' => $currentUser->id,
                        ]);
                        $count++;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => "Marked {$count} staff members as Present for {$date}.",
                'marked_count' => $count,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to mark all present: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get monthly attendance summary.
     */
    public function getMonthlySummary(Request $request)
    {
        $month = $request->input('month', Carbon::today()->format('Y-m')); // e.g. 2026-09
        $startDate = "{$month}-01";
        $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

        $attendances = StaffAttendance::whereBetween('date', [$startDate, $endDate])->get();

        $totalRecords = $attendances->count();
        $presents = $attendances->where('status', 'present')->count();
        $lates = $attendances->where('status', 'late')->count();
        $absents = $attendances->where('status', 'absent')->count();
        $leaves = $attendances->where('status', 'on_leave')->count();

        return response()->json([
            'month' => $month,
            'summary' => [
                'total_records' => $totalRecords,
                'presents' => $presents,
                'lates' => $lates,
                'absents' => $absents,
                'leaves' => $leaves,
                'attendance_rate' => $totalRecords > 0 ? round((($presents + $lates) / $totalRecords) * 100, 1) : 0,
            ],
        ]);
    }
}
