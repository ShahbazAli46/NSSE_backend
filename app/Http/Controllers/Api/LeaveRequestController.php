<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\StaffAttendance;
use App\Models\Teacher;
use App\Models\StaffMember;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveRequestController extends Controller
{
    protected $approverRoles = ['principal', 'super_admin', 'admin', 'chairman', 'director'];

    /**
     * List all leave applications with filters and KPI stats (Principal, HR, Admin).
     */
    public function index(Request $request)
    {
        $query = LeaveRequest::query();

        if ($request->has('month') && !empty($request->month)) {
            $month = $request->month; // e.g. "2026-09"
            try {
                $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();
                $endDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])
                      ->orWhereBetween('end_date', [$startDate, $endDate])
                      ->orWhere(function ($sub) use ($startDate, $endDate) {
                          $sub->where('start_date', '<=', $startDate)
                              ->where('end_date', '>=', $endDate);
                      });
                });
            } catch (\Exception $e) {
                // Ignore invalid format
            }
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('staff_type') && $request->staff_type !== 'all') {
            $query->where('staff_type', $request->staff_type);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('staff_name', 'like', "%{$search}%")
                  ->orWhere('designation', 'like', "%{$search}%")
                  ->orWhere('reason', 'like', "%{$search}%")
                  ->orWhere('leave_type', 'like', "%{$search}%");
            });
        }

        $leaves = $query->orderBy('created_at', 'desc')->get();
        $today = Carbon::today()->toDateString();

        $stats = [
            'total_requests' => $leaves->count(),
            'pending_count' => $leaves->where('status', 'pending')->count(),
            'approved_count' => $leaves->where('status', 'approved')->count(),
            'rejected_count' => $leaves->where('status', 'rejected')->count(),
            'total_approved_days' => (int) $leaves->where('status', 'approved')->sum('total_days'),
            'active_leaves_today' => LeaveRequest::where('status', 'approved')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->count(),
        ];

        return response()->json([
            'status' => 'success',
            'stats' => $stats,
            'data' => $leaves,
        ]);
    }

    /**
     * Teacher / Staff views their own leave history with optional month filter.
     */
    public function myLeaves(Request $request)
    {
        $currentUser = $request->user();

        $teacher = Teacher::where('user_id', $currentUser->id)->first();
        $staff = StaffMember::where('user_id', $currentUser->id)->first();

        $query = LeaveRequest::where(function ($q) use ($currentUser, $teacher, $staff) {
            $q->where('user_id', $currentUser->id);
            if ($teacher) {
                $q->orWhere(function ($sub) use ($teacher) {
                    $sub->where('staff_type', 'teacher')->where('staff_id', $teacher->id);
                });
            }
            if ($staff) {
                $q->orWhere(function ($sub) use ($staff) {
                    $sub->where('staff_type', 'non_teaching')->where('staff_id', $staff->id);
                });
            }
        });

        if ($request->has('month') && !empty($request->month)) {
            $month = $request->month;
            try {
                $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();
                $endDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])
                      ->orWhereBetween('end_date', [$startDate, $endDate])
                      ->orWhere(function ($sub) use ($startDate, $endDate) {
                          $sub->where('start_date', '<=', $startDate)
                              ->where('end_date', '>=', $endDate);
                      });
                });
            } catch (\Exception $e) {
                // Ignore invalid format
            }
        }

        $leaves = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $leaves,
        ]);
    }

    /**
     * Teacher / Staff submits a new leave request.
     */
    public function store(Request $request)
    {
        $currentUser = $request->user();

        $request->validate([
            'leave_type' => 'required|in:casual,sick,emergency,annual,maternity,other',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
        ]);

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->startOfDay();
        $totalDays = $startDate->diffInDays($endDate) + 1;

        $staffType = 'teacher';
        $staffId = 0;
        $staffName = $currentUser->name;
        $designation = 'Teaching Faculty';

        $teacher = Teacher::where('user_id', $currentUser->id)->first();
        if ($teacher) {
            $staffType = 'teacher';
            $staffId = $teacher->id;
            $staffName = $teacher->name;
            $designation = $teacher->qualification ? "Faculty ({$teacher->qualification})" : 'Teaching Faculty';
        } else {
            $staff = StaffMember::where('user_id', $currentUser->id)->first();
            if ($staff) {
                $staffType = 'non_teaching';
                $staffId = $staff->id;
                $staffName = $staff->name;
                $designation = $staff->designation ?: 'Staff Member';
            }
        }

        $leave = LeaveRequest::create([
            'user_id' => $currentUser->id,
            'staff_type' => $staffType,
            'staff_id' => $staffId,
            'staff_name' => $staffName,
            'designation' => $designation,
            'leave_type' => $request->leave_type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'total_days' => $totalDays,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Leave application submitted successfully for Principal review.',
            'data' => $leave,
        ], 201);
    }

    /**
     * Principal approves leave request & auto-records 'on_leave' attendance.
     */
    public function approve(Request $request, $id)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, $this->approverRoles)) {
            return response()->json(['message' => 'Unauthorized. Only the Principal or Executive Administration can approve leave requests.'], 403);
        }

        $leave = LeaveRequest::findOrFail($id);

        DB::beginTransaction();
        try {
            $approverName = $currentUser->name ?: 'Executive Principal';

            $leave->update([
                'status' => 'approved',
                'approved_by' => $currentUser->id,
                'approver_name' => $approverName,
                'approver_remarks' => $request->input('remarks', 'Approved by Principal'),
                'approved_at' => Carbon::now(),
            ]);

            // Auto-generate / lock StaffAttendance records for every date in range
            $period = CarbonPeriod::create($leave->start_date, $leave->end_date);
            $userId = $leave->user_id;

            foreach ($period as $date) {
                $dateStr = $date->toDateString();
                StaffAttendance::updateOrCreate(
                    [
                        'staff_type' => $leave->staff_type,
                        'staff_id' => $leave->staff_id,
                        'date' => $dateStr,
                    ],
                    [
                        'user_id' => $userId,
                        'status' => 'on_leave',
                        'check_in_time' => null,
                        'check_out_time' => null,
                        'remarks' => "Official Leave: " . ucfirst($leave->leave_type) . " ({$leave->reason}) - Approved by {$approverName}",
                        'marked_by' => $currentUser->id,
                    ]
                );
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Leave application approved for {$leave->staff_name} ({$leave->total_days} days). Attendance records have been marked as Official Leave.",
                'data' => $leave->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to approve leave: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Principal rejects leave request.
     */
    public function reject(Request $request, $id)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, $this->approverRoles)) {
            return response()->json(['message' => 'Unauthorized. Only the Principal or Executive Administration can reject leave requests.'], 403);
        }

        $leave = LeaveRequest::findOrFail($id);

        $approverName = $currentUser->name ?: 'Executive Principal';

        $leave->update([
            'status' => 'rejected',
            'approved_by' => $currentUser->id,
            'approver_name' => $approverName,
            'approver_remarks' => $request->input('remarks', 'Declined by Principal'),
            'approved_at' => Carbon::now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Leave application for {$leave->staff_name} has been rejected.",
            'data' => $leave->fresh(),
        ]);
    }
}
