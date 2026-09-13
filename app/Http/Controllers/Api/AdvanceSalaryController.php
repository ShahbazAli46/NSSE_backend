<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvanceSalaryRequest;
use App\Models\Teacher;
use App\Models\StaffMember;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdvanceSalaryController extends Controller
{
    /**
     * List advance salary requests (for Principal, Directors, HR, Accountant)
     */
    public function index(Request $request)
    {
        $query = AdvanceSalaryRequest::query();

        if ($request->has('month') && !empty($request->month)) {
            $month = $request->month;
            try {
                $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
                $endDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth();
                $query->whereBetween('created_at', [$startDate, $endDate]);
            } catch (\Exception $e) {
                // Ignore invalid format
            }
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('disbursement_status') && $request->disbursement_status !== 'all') {
            $query->where('disbursement_status', $request->disbursement_status);
        }

        if ($request->has('staff_type') && $request->staff_type !== 'all') {
            $query->where('staff_type', $request->staff_type);
        }

        if ($request->has('staff_id')) {
            $query->where('staff_id', $request->staff_id);
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        $stats = [
            'total_requests' => $requests->count(),
            'pending_count' => $requests->where('status', 'pending')->count(),
            'approved_count' => $requests->where('status', 'approved')->count(),
            'pending_disbursement_count' => $requests->where('status', 'approved')->where('disbursement_status', 'pending')->count(),
            'disbursed_count' => $requests->where('disbursement_status', 'disbursed')->count(),
            'rejected_count' => $requests->where('status', 'rejected')->count(),
            'total_approved_amount' => round($requests->where('status', 'approved')->sum('amount'), 2),
            'total_pending_amount' => round($requests->where('status', 'pending')->sum('amount'), 2),
            'total_pending_disbursement_amount' => round($requests->where('status', 'approved')->where('disbursement_status', 'pending')->sum('amount'), 2),
            'total_disbursed_amount' => round($requests->where('disbursement_status', 'disbursed')->sum('amount'), 2),
        ];

        return response()->json([
            'status' => 'success',
            'stats' => $stats,
            'requests' => $requests,
        ]);
    }

    /**
     * Teacher / Staff submits advance salary request
     */
    public function store(Request $request)
    {
        $currentUser = $request->user();

        $request->validate([
            'amount' => 'required|numeric|min:1|max:9999999',
            'reason' => 'required|string|max:1000',
            'repayment_plan' => 'nullable|string|max:255',
        ]);

        $teacher = Teacher::where('user_id', $currentUser->id)->first();
        if ($teacher) {
            $staffType = 'teacher';
            $staffId = $teacher->id;
            $staffName = $teacher->name;
            $designation = $teacher->qualification ? 'Faculty (' . $teacher->qualification . ')' : 'Teaching Faculty';
        } else {
            // Check if staff member
            $staff = StaffMember::where('user_id', $currentUser->id)->first();
            if ($staff) {
                $staffType = 'non_teaching';
                $staffId = $staff->id;
                $staffName = $staff->name;
                $designation = $staff->designation;
            } else {
                $staffType = 'teacher';
                $staffId = $currentUser->id;
                $staffName = $currentUser->name;
                $designation = 'Staff Member';
            }
        }

        $advRequest = AdvanceSalaryRequest::create([
            'staff_type' => $staffType,
            'staff_id' => $staffId,
            'user_id' => $currentUser->id,
            'staff_name' => $staffName,
            'designation' => $designation,
            'amount' => (float) $request->amount,
            'reason' => trim($request->reason),
            'repayment_plan' => $request->repayment_plan ? trim($request->repayment_plan) : 'Deduct from monthly payroll',
            'status' => 'pending',
            'disbursement_status' => 'pending',
            'total_recovered' => 0,
            'remaining_balance' => (float) $request->amount,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Advance salary request submitted successfully. It has been routed to the Principal and Board of Directors for executive approval.',
            'request' => $advRequest,
        ], 201);
    }

    /**
     * Get Logged-in Teacher's own advance requests
     */
    public function myRequests(Request $request)
    {
        $currentUser = $request->user();

        $requests = AdvanceSalaryRequest::where('user_id', $currentUser->id)
            ->orWhere(function ($q) use ($currentUser) {
                $teacher = Teacher::where('user_id', $currentUser->id)->first();
                if ($teacher) {
                    $q->where('staff_type', 'teacher')->where('staff_id', $teacher->id);
                }
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'requests' => $requests,
        ]);
    }

    /**
     * Approve advance salary request (Principal, Directors, Chairman, Admin)
     */
    public function approve(Request $request, $id)
    {
        $currentUser = $request->user();
        $isAuthorized = in_array($currentUser->role ?? '', ['principal', 'director', 'chairman', 'super_admin', 'admin']);

        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Unauthorized. Only the Principal or Board of Directors have executive authority to approve salary advances.',
            ], 403);
        }

        $adv = AdvanceSalaryRequest::findOrFail($id);

        // Strict Status Locking: Cannot re-review already approved or rejected requests
        if ($adv->status !== 'pending') {
            $reviewerStr = $adv->reviewed_by_name ? "by {$adv->reviewed_by_name}" : '';
            return response()->json([
                'message' => "Action locked: This advance request has already been {$adv->status} {$reviewerStr}. Finalized requests cannot be modified.",
            ], 422);
        }

        $reviewerRole = ucfirst(str_replace('_', ' ', $currentUser->role ?? 'Executive'));
        $reviewerName = "{$currentUser->name} ({$reviewerRole})";

        $adv->status = 'approved';
        $adv->reviewed_by = $currentUser->id;
        $adv->reviewed_by_name = $reviewerName;
        $adv->reviewed_by_role = $currentUser->role;
        $adv->reviewed_at = now();
        $adv->review_remarks = $request->input('remarks', 'Advance salary approved. Forwarded to Accounts for disbursement.');
        $adv->disbursement_status = 'pending';
        $adv->save();

        return response()->json([
            'status' => 'success',
            'message' => "Advance salary request of PKR " . number_format($adv->amount) . " for {$adv->staff_name} approved successfully by {$reviewerName}. Ready for Accounts disbursement.",
            'request' => $adv,
        ]);
    }

    /**
     * Reject advance salary request (Principal, Directors, Chairman, Admin)
     */
    public function reject(Request $request, $id)
    {
        $currentUser = $request->user();
        $isAuthorized = in_array($currentUser->role ?? '', ['principal', 'director', 'chairman', 'super_admin', 'admin']);

        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Unauthorized. Only the Principal or Board of Directors have executive authority to reject salary advances.',
            ], 403);
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $adv = AdvanceSalaryRequest::findOrFail($id);

        // Strict Status Locking: Cannot re-review already approved or rejected requests
        if ($adv->status !== 'pending') {
            $reviewerStr = $adv->reviewed_by_name ? "by {$adv->reviewed_by_name}" : '';
            return response()->json([
                'message' => "Action locked: This advance request has already been {$adv->status} {$reviewerStr}. Finalized requests cannot be modified.",
            ], 422);
        }

        $reviewerRole = ucfirst(str_replace('_', ' ', $currentUser->role ?? 'Executive'));
        $reviewerName = "{$currentUser->name} ({$reviewerRole})";

        $adv->status = 'rejected';
        $adv->reviewed_by = $currentUser->id;
        $adv->reviewed_by_name = $reviewerName;
        $adv->reviewed_by_role = $currentUser->role;
        $adv->reviewed_at = now();
        $adv->rejection_reason = trim($request->rejection_reason);
        $adv->review_remarks = $request->input('remarks');
        $adv->save();

        return response()->json([
            'status' => 'success',
            'message' => "Advance salary request for {$adv->staff_name} was rejected by {$reviewerName}.",
            'request' => $adv,
        ]);
    }

    /**
     * Disburse approved advance salary request (Accountant, HR Accountant, Admin, Director)
     */
    public function disburse(Request $request, $id)
    {
        $currentUser = $request->user();
        $isAuthorized = in_array($currentUser->role ?? '', ['accountant', 'hr_accountant', 'director', 'chairman', 'super_admin', 'admin']);

        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Unauthorized. Only Finance / Accounts Officers or Directors have authority to disburse salary advances.',
            ], 403);
        }

        $request->validate([
            'payment_source' => 'required|in:bank,cash',
            'bank_name' => 'nullable|string|max:255',
            'reference_no' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $adv = AdvanceSalaryRequest::findOrFail($id);

        if ($adv->status !== 'approved') {
            return response()->json([
                'message' => 'Only Principal / Director-approved requests can be disbursed.',
            ], 422);
        }

        if ($adv->disbursement_status === 'disbursed') {
            return response()->json([
                'message' => 'This advance request has already been disbursed.',
            ], 422);
        }

        $paymentSource = $request->payment_source; // 'bank' or 'cash'
        $disburserRole = ucfirst(str_replace('_', ' ', $currentUser->role ?? 'Finance'));
        $disburserName = "{$currentUser->name} ({$disburserRole})";

        $adv->disbursement_status = 'disbursed';
        $adv->disbursed_at = now();
        $adv->disbursed_by = $currentUser->id;
        $adv->disbursed_by_name = $disburserName;
        $adv->payment_source = $paymentSource;
        $adv->payment_reference = $request->reference_no;
        $adv->disbursement_notes = $request->notes;
        $adv->save();

        // Create General Expense entry to reflect deduction in Bank Balance or Cash Drawer
        $paymentMethod = ($paymentSource === 'bank') ? 'Bank Transfer' : 'Cash';
        $voucherNo = 'VCH-ADV-' . str_pad($adv->id, 5, '0', STR_PAD_LEFT);

        Expense::create([
            'voucher_no' => $voucherNo,
            'title' => "Advance Salary Disbursement - {$adv->staff_name}",
            'category' => 'Salaries & Advances',
            'amount' => $adv->amount,
            'description' => "Salary advance payment of PKR " . number_format($adv->amount) . " to {$adv->staff_name} ({$adv->designation}). Payment Source: " . ($paymentSource === 'bank' ? 'School Bank Account' : 'Cash Drawer') . ($request->reference_no ? " | Ref: {$request->reference_no}" : "") . ($request->notes ? " | Notes: {$request->notes}" : ""),
            'created_by' => $currentUser->id,
            'status' => 'disbursed',
            'disbursed_at' => now(),
            'disbursed_by' => $currentUser->id,
            'payment_method' => $paymentMethod,
            'payment_reference' => $request->reference_no,
            'expense_type' => 'general',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Advance salary of PKR " . number_format($adv->amount) . " for {$adv->staff_name} has been successfully disbursed from " . ($paymentSource === 'bank' ? 'School Bank Account' : 'Cash Drawer') . ".",
            'request' => $adv,
        ]);
    }
}
