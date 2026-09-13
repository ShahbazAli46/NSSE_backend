<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\Teacher;
use App\Models\StaffMember;
use App\Models\StaffAttendance;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    /**
     * Get or Generate Monthly Payroll Sheet
     */
    public function index(Request $request)
    {
        $month = $request->query('month', Carbon::now()->format('Y-m'));
        $staffType = $request->query('type', 'all'); // all, teaching, non_teaching

        // Determine days and working days in month (excluding Sundays)
        try {
            $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $endDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth();
        } catch (\Exception $e) {
            $startDate = Carbon::now()->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();
            $month = $startDate->format('Y-m');
        }

        $totalDaysInMonth = $startDate->daysInMonth;
        $workingDays = 0;
        $temp = $startDate->copy();
        while ($temp <= $endDate) {
            if ($temp->dayOfWeek !== Carbon::SUNDAY) {
                $workingDays++;
            }
            $temp->addDay();
        }
        $workingDays = max($workingDays, 1);

        // Fetch all active/on_leave faculty
        $teachers = collect();
        $nonTeaching = collect();

        $currentUser = $request->user();

        if ($currentUser && $currentUser->role === 'teacher') {
            $teacher = Teacher::with('user')->where('user_id', $currentUser->id)->first();
            $teachers = $teacher ? collect([$teacher]) : collect();
        } elseif ($currentUser && !in_array($currentUser->role, ['accountant', 'hr_accountant', 'hr', 'director', 'principal', 'super_admin', 'admin', 'chairman'])) {
            $staff = StaffMember::with('user')->where('user_id', $currentUser->id)->first();
            if ($staff) {
                $nonTeaching = collect([$staff]);
            }
        } else {
            if ($staffType === 'all' || $staffType === 'teaching') {
                $teachers = Teacher::with('user')->where('status', '!=', 'inactive')->get();
            }

            // Fetch all active/on_leave staff members
            if ($staffType === 'all' || $staffType === 'non_teaching') {
                $nonTeaching = StaffMember::with('user')->where('status', '!=', 'inactive')->get();
            }
        }

        $records = [];

        // Process Teachers
        foreach ($teachers as $teacher) {
            $existing = Payroll::where('month', $month)
                ->where('staff_type', 'teacher')
                ->where('staff_id', $teacher->id)
                ->first();

            $attStats = $this->calculateAttendanceStats('teacher', $teacher->id, $startDate, $endDate);

            $baseSalary = (float) ($existing ? $existing->base_salary : $teacher->salary);
            $allowances = is_array($teacher->allowances) ? $teacher->allowances : [];
            $totalAllowances = (float) $teacher->total_allowances;

            // Preserve existing manual bonuses if any
            $bonuses = ($existing && is_array($existing->bonuses)) ? $existing->bonuses : [];
            $totalBonuses = (float) ($existing ? $existing->total_bonuses : 0.0);

            $grossSalary = $baseSalary + $totalAllowances + $totalBonuses;

            $allowedLeaves = $existing && $existing->allowed_leaves !== null ? (int) $existing->allowed_leaves : 2;
            $leaveDays = $existing && $existing->leave_days !== null ? (int) $existing->leave_days : (int) $attStats['leave_count'];
            $absentDays = $existing && $existing->absent_days !== null ? (int) $existing->absent_days : (int) $attStats['absent_count'];

            $excessLeaves = max(0, $leaveDays - $allowedLeaves);
            $deductibleDays = $absentDays + $excessLeaves;

            $dailyRate = $workingDays > 0 ? round(($baseSalary + $totalAllowances) / $workingDays, 2) : 0;
            $absentDeduction = ($existing && $existing->absent_deduction !== null)
                ? (float) $existing->absent_deduction
                : round($deductibleDays * $dailyRate, 2);

            $activeAdvance = \App\Models\AdvanceSalaryRequest::where('staff_type', 'teacher')
                ->where('staff_id', $teacher->id)
                ->where('disbursement_status', 'disbursed')
                ->where('remaining_balance', '>', 0)
                ->first();

            $advanceDeduction = ($existing && $existing->advance_deduction !== null)
                ? (float) $existing->advance_deduction
                : ($activeAdvance ? (float) $activeAdvance->remaining_balance : 0.0);

            $customDeductions = ($existing && is_array($existing->custom_deductions)) ? $existing->custom_deductions : [];
            
            $totalCustomDeductions = 0.0;
            foreach ($customDeductions as $cd) {
                $totalCustomDeductions += (float) ($cd['amount'] ?? 0);
            }

            $otherDeductions = round($advanceDeduction + $totalCustomDeductions, 2);
            $netSalary = max(0, round($grossSalary - $absentDeduction - $otherDeductions, 2));

            $payroll = Payroll::updateOrCreate(
                [
                    'month' => $month,
                    'staff_type' => 'teacher',
                    'staff_id' => $teacher->id,
                ],
                [
                    'staff_name' => $teacher->name,
                    'designation' => $teacher->qualification ? 'Faculty (' . $teacher->qualification . ')' : 'Teaching Faculty',
                    'base_salary' => $baseSalary,
                    'allowances' => $allowances,
                    'total_allowances' => $totalAllowances,
                    'bonuses' => $bonuses,
                    'total_bonuses' => $totalBonuses,
                    'gross_salary' => $grossSalary,
                    'total_working_days' => $workingDays,
                    'present_days' => $attStats['present_count'],
                    'late_days' => $attStats['late_count'],
                    'absent_days' => $absentDays,
                    'leave_days' => $leaveDays,
                    'allowed_leaves' => $allowedLeaves,
                    'deductible_days' => $deductibleDays,
                    'daily_rate' => $dailyRate,
                    'absent_deduction' => $absentDeduction,
                    'advance_deduction' => $advanceDeduction,
                    'custom_deductions' => $customDeductions,
                    'other_deductions' => $otherDeductions,
                    'net_salary' => $netSalary,
                    'payment_method' => $existing ? $existing->payment_method : ($teacher->payment_method ?: 'bank'),
                    'bank_name' => $existing ? $existing->bank_name : $teacher->bank_name,
                    'account_title' => $existing ? $existing->account_title : $teacher->account_title,
                    'account_number' => $existing ? $existing->account_number : $teacher->account_number,
                    'status' => $existing ? $existing->status : 'generated',
                    'remarks' => $existing ? $existing->remarks : null,
                    'created_by' => Auth::id(),
                ]
            );

            $payroll->staff_profile = $teacher;
            $records[] = $payroll;
        }

        // Process Non-Teaching Staff
        foreach ($nonTeaching as $staff) {
            $existing = Payroll::where('month', $month)
                ->where('staff_type', 'non_teaching')
                ->where('staff_id', $staff->id)
                ->first();

            $attStats = $this->calculateAttendanceStats('non_teaching', $staff->id, $startDate, $endDate);

            $baseSalary = (float) ($existing ? $existing->base_salary : $staff->salary);
            $allowances = is_array($staff->allowances) ? $staff->allowances : [];
            $totalAllowances = (float) $staff->total_allowances;

            $bonuses = ($existing && is_array($existing->bonuses)) ? $existing->bonuses : [];
            $totalBonuses = (float) ($existing ? $existing->total_bonuses : 0.0);

            $grossSalary = $baseSalary + $totalAllowances + $totalBonuses;

            $allowedLeaves = $existing && $existing->allowed_leaves !== null ? (int) $existing->allowed_leaves : 2;
            $leaveDays = $existing && $existing->leave_days !== null ? (int) $existing->leave_days : (int) $attStats['leave_count'];
            $absentDays = $existing && $existing->absent_days !== null ? (int) $existing->absent_days : (int) $attStats['absent_count'];

            $excessLeaves = max(0, $leaveDays - $allowedLeaves);
            $deductibleDays = $absentDays + $excessLeaves;

            $dailyRate = $workingDays > 0 ? round(($baseSalary + $totalAllowances) / $workingDays, 2) : 0;
            $absentDeduction = ($existing && $existing->absent_deduction !== null)
                ? (float) $existing->absent_deduction
                : round($deductibleDays * $dailyRate, 2);

            $activeStaffAdvance = \App\Models\AdvanceSalaryRequest::where('staff_type', 'non_teaching')
                ->where('staff_id', $staff->id)
                ->where('disbursement_status', 'disbursed')
                ->where('remaining_balance', '>', 0)
                ->first();

            $advanceDeduction = ($existing && $existing->advance_deduction !== null)
                ? (float) $existing->advance_deduction
                : ($activeStaffAdvance ? (float) $activeStaffAdvance->remaining_balance : 0.0);
            $customDeductions = ($existing && is_array($existing->custom_deductions)) ? $existing->custom_deductions : [];
            
            $totalCustomDeductions = 0.0;
            foreach ($customDeductions as $cd) {
                $totalCustomDeductions += (float) ($cd['amount'] ?? 0);
            }

            $otherDeductions = round($advanceDeduction + $totalCustomDeductions, 2);
            $netSalary = max(0, round($grossSalary - $absentDeduction - $otherDeductions, 2));

            $payroll = Payroll::updateOrCreate(
                [
                    'month' => $month,
                    'staff_type' => 'non_teaching',
                    'staff_id' => $staff->id,
                ],
                [
                    'staff_name' => $staff->name,
                    'designation' => $staff->designation,
                    'base_salary' => $baseSalary,
                    'allowances' => $allowances,
                    'total_allowances' => $totalAllowances,
                    'bonuses' => $bonuses,
                    'total_bonuses' => $totalBonuses,
                    'gross_salary' => $grossSalary,
                    'total_working_days' => $workingDays,
                    'present_days' => $attStats['present_count'],
                    'late_days' => $attStats['late_count'],
                    'absent_days' => $absentDays,
                    'leave_days' => $leaveDays,
                    'allowed_leaves' => $allowedLeaves,
                    'deductible_days' => $deductibleDays,
                    'daily_rate' => $dailyRate,
                    'absent_deduction' => $absentDeduction,
                    'advance_deduction' => $advanceDeduction,
                    'custom_deductions' => $customDeductions,
                    'other_deductions' => $otherDeductions,
                    'net_salary' => $netSalary,
                    'payment_method' => $existing ? $existing->payment_method : ($staff->payment_method ?: 'cash'),
                    'bank_name' => $existing ? $existing->bank_name : $staff->bank_name,
                    'account_title' => $existing ? $existing->account_title : $staff->account_title,
                    'account_number' => $existing ? $existing->account_number : $staff->account_number,
                    'remarks' => $existing ? $existing->remarks : null,
                    'created_by' => Auth::id(),
                ]
            );

            $payroll->staff_profile = $staff;
            $records[] = $payroll;
        }

        // Compute Summary Stats
        $totalGross = collect($records)->sum('gross_salary');
        $totalDeductions = collect($records)->sum('absent_deduction') + collect($records)->sum('other_deductions');
        $totalNetPayout = collect($records)->sum('net_salary');
        $bankPayoutTotal = collect($records)->where('payment_method', 'bank')->sum('net_salary');
        $cashPayoutTotal = collect($records)->where('payment_method', 'cash')->sum('net_salary');
        $paidCount = collect($records)->where('status', 'paid')->count();
        $unpaidCount = collect($records)->where('status', '!=', 'paid')->count();

        return response()->json([
            'status' => 'success',
            'month' => $month,
            'working_days' => $workingDays,
            'stats' => [
                'total_staff' => count($records),
                'total_gross' => round($totalGross, 2),
                'total_deductions' => round($totalDeductions, 2),
                'total_net_payout' => round($totalNetPayout, 2),
                'bank_payout_total' => round($bankPayoutTotal, 2),
                'cash_payout_total' => round($cashPayoutTotal, 2),
                'paid_count' => $paidCount,
                'unpaid_count' => $unpaidCount,
            ],
            'payrolls' => $records,
        ]);
    }

    /**
     * Update/Adjust single Payroll item (Bonuses, Advance Deductions, Custom Deductions, Notes)
     */
    public function update(Request $request, $id)
    {
        $payroll = Payroll::findOrFail($id);
        $currentUser = $request->user();

        if ($currentUser && !in_array($currentUser->role, ['accountant', 'hr_accountant', 'hr', 'director', 'principal', 'super_admin', 'admin', 'chairman'])) {
            return response()->json([
                'message' => 'Unauthorized. Only accounts and HR personnel have authority to edit payroll records.',
            ], 403);
        }

        $request->validate([
            'base_salary' => 'nullable|numeric|min:0',
            'allowed_leaves' => 'nullable|integer|min:0',
            'leave_days' => 'nullable|integer|min:0',
            'absent_days' => 'nullable|integer|min:0',
            'absent_deduction' => 'nullable|numeric|min:0',
            'advance_deduction' => 'nullable|numeric|min:0',
            'bonuses' => 'nullable|array',
            'custom_deductions' => 'nullable|array',
            'payment_method' => 'nullable|in:bank,cash',
            'bank_name' => 'nullable|string|max:100',
            'account_title' => 'nullable|string|max:150',
            'account_number' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:1000',
            'status' => 'nullable|in:generated,paid',
        ]);

        // Paid status lock: Prevent modifying locked/paid records
        if ($payroll->status === 'paid' && !$request->has('status')) {
            return response()->json([
                'message' => 'This payroll record has already been marked as PAID and is locked against modifications.',
            ], 422);
        }

        // Authorization Check: Only Principal, Super Admin, Admin, or Chairman can award/modify bonuses
        $totalBonuses = (float) $payroll->total_bonuses;
        if ($request->has('bonuses')) {
            $isAuthorizedPrincipal = in_array($currentUser->role ?? '', ['principal', 'super_admin', 'admin', 'chairman']);
            
            $existingBonuses = is_array($payroll->bonuses) ? $payroll->bonuses : [];
            $incomingBonuses = is_array($request->bonuses) ? $request->bonuses : [];
            
            $existingKeys = array_map(fn($b) => ($b['title'] ?? '') . '_' . (float)($b['amount'] ?? 0), $existingBonuses);
            $incomingKeys = array_map(fn($b) => ($b['title'] ?? '') . '_' . (float)($b['amount'] ?? 0), $incomingBonuses);
            
            $isModified = ($existingKeys !== $incomingKeys);
            
            if ($isModified && !$isAuthorizedPrincipal) {
                return response()->json([
                    'message' => 'Unauthorized. Only the Principal or Campus Administration has the executive authority to award or modify employee bonuses.',
                ], 403);
            }
            
            if ($isAuthorizedPrincipal) {
                $bonuses = [];
                $totalBonuses = 0.0;
                $approverName = $currentUser ? $currentUser->name : 'Principal';
                foreach ($incomingBonuses as $b) {
                    if (!empty($b['title']) && isset($b['amount'])) {
                        $amt = (float) $b['amount'];
                        if ($amt > 0) {
                            $bonuses[] = [
                                'title' => trim($b['title']),
                                'amount' => $amt,
                                'approved_by' => !empty($b['approved_by']) ? $b['approved_by'] : $approverName,
                                'approved_by_id' => !empty($b['approved_by_id']) ? $b['approved_by_id'] : ($currentUser ? $currentUser->id : null),
                                'approved_at' => !empty($b['approved_at']) ? $b['approved_at'] : now()->toIso8601String(),
                            ];
                            $totalBonuses += $amt;
                        }
                    }
                }
                $payroll->bonuses = $bonuses;
                $payroll->total_bonuses = $totalBonuses;
            }
        }

        if ($request->has('base_salary')) {
            $payroll->base_salary = (float) $request->base_salary;
        }

        // Gross = Base + Allowances + Bonuses
        $payroll->gross_salary = (float) $payroll->base_salary + (float) $payroll->total_allowances + $totalBonuses;

        // Leave & Absent Policy Adjustments
        if ($request->has('allowed_leaves')) {
            $payroll->allowed_leaves = max(0, (int) $request->allowed_leaves);
        }
        if ($request->has('leave_days')) {
            $payroll->leave_days = max(0, (int) $request->leave_days);
        }
        if ($request->has('absent_days')) {
            $payroll->absent_days = max(0, (int) $request->absent_days);
        }

        $workingDays = $payroll->total_working_days ?: 26;
        $payroll->daily_rate = $workingDays > 0 ? round(((float)$payroll->base_salary + (float)$payroll->total_allowances) / $workingDays, 2) : 0;

        $excessLeaves = max(0, (int)$payroll->leave_days - (int)$payroll->allowed_leaves);
        $deductibleDays = (int)$payroll->absent_days + $excessLeaves;
        $payroll->deductible_days = $deductibleDays;

        // Absent deduction
        if ($request->has('absent_deduction')) {
            $payroll->absent_deduction = (float) $request->absent_deduction;
        } else {
            $payroll->absent_deduction = round($deductibleDays * $payroll->daily_rate, 2);
        }

        // Advance deduction
        if ($request->has('advance_deduction')) {
            $payroll->advance_deduction = (float) $request->advance_deduction;
        }

        // Custom deductions
        $customDeductions = [];
        $totalCustomDeductions = 0.0;
        if ($request->has('custom_deductions')) {
            foreach ($request->custom_deductions as $cd) {
                if (!empty($cd['title']) && isset($cd['amount'])) {
                    $amt = (float) $cd['amount'];
                    if ($amt > 0) {
                        $customDeductions[] = [
                            'title' => trim($cd['title']),
                            'amount' => $amt,
                        ];
                        $totalCustomDeductions += $amt;
                    }
                }
            }
            $payroll->custom_deductions = $customDeductions;
        } else {
            if (is_array($payroll->custom_deductions)) {
                foreach ($payroll->custom_deductions as $cd) {
                    $totalCustomDeductions += (float) ($cd['amount'] ?? 0);
                }
            }
        }

        $payroll->other_deductions = (float) $payroll->advance_deduction + $totalCustomDeductions;

        // Net Salary
        $payroll->net_salary = max(0, round((float) $payroll->gross_salary - (float) $payroll->absent_deduction - (float) $payroll->other_deductions, 2));

        if ($request->has('payment_method')) $payroll->payment_method = $request->payment_method;
        if ($request->has('bank_name')) $payroll->bank_name = $request->bank_name;
        if ($request->has('account_title')) $payroll->account_title = $request->account_title;
        if ($request->has('account_number')) $payroll->account_number = $request->account_number;
        if ($request->has('remarks')) $payroll->remarks = $request->remarks;
        if ($request->has('status')) {
            $payroll->status = $request->status;
            if ($request->status === 'paid' && !$payroll->paid_at) {
                $payroll->paid_at = Carbon::now();
            }
        }

        $payroll->save();
        $payroll->staff_profile = $payroll->staff;

        return response()->json([
            'status' => 'success',
            'message' => "Payroll for {$payroll->staff_name} updated successfully.",
            'payroll' => $payroll,
        ]);
    }

    /**
     * Mark single payroll as paid
     */
    public function markPaid(Request $request, $id)
    {
        $currentUser = $request->user();
        $isAccountant = in_array($currentUser->role ?? '', ['accountant', 'hr_accountant', 'super_admin', 'director', 'chairman']);
        if (!$isAccountant) {
            return response()->json([
                'message' => 'Unauthorized. Only Finance & Accounts personnel have authority to disburse or mark salaries as PAID.',
            ], 403);
        }

        $payroll = Payroll::findOrFail($id);
        $newStatus = ($payroll->status === 'paid') ? 'generated' : 'paid';
        $payroll->status = $newStatus;
        $payroll->paid_at = ($newStatus === 'paid') ? Carbon::now() : null;
        $payroll->save();

        $voucherNo = 'VCH-SAL-' . str_pad($payroll->id, 5, '0', STR_PAD_LEFT);
        $userId = $currentUser ? $currentUser->id : 1;

        if ($newStatus === 'paid') {
            $paymentMethod = ($payroll->payment_method === 'bank') ? 'Bank Transfer' : 'Cash';
            Expense::updateOrCreate(
                ['voucher_no' => $voucherNo],
                [
                    'title' => "Staff Monthly Salary - {$payroll->staff_name} ({$payroll->month})",
                    'category' => 'Salaries & Advances',
                    'amount' => $payroll->net_salary,
                    'description' => "Monthly remuneration payout for {$payroll->staff_name} ({$payroll->designation}) for {$payroll->month}. Payment Method: " . ($payroll->payment_method === 'bank' ? 'School Bank Transfer' : 'Cash Counter'),
                    'created_by' => $userId,
                    'status' => 'disbursed',
                    'disbursed_at' => now(),
                    'disbursed_by' => $userId,
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $payroll->account_number,
                    'expense_type' => 'general',
                ]
            );
        } else {
            Expense::where('voucher_no', $voucherNo)->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payroll status updated.',
            'payroll' => $payroll,
        ]);
    }

    /**
     * Mark all payrolls for month as paid
     */
    public function markAllPaid(Request $request)
    {
        $currentUser = $request->user();
        $isAccountant = in_array($currentUser->role ?? '', ['accountant', 'hr_accountant', 'super_admin', 'director', 'chairman']);
        if (!$isAccountant) {
            return response()->json([
                'message' => 'Unauthorized. Only Finance & Accounts personnel have authority to disburse or mark salaries as PAID.',
            ], 403);
        }

        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $payrolls = Payroll::where('month', $month)->where('status', 'generated')->get();
        $userId = $currentUser ? $currentUser->id : 1;

        foreach ($payrolls as $payroll) {
            $payroll->status = 'paid';
            $payroll->paid_at = Carbon::now();
            $payroll->save();

            $voucherNo = 'VCH-SAL-' . str_pad($payroll->id, 5, '0', STR_PAD_LEFT);
            $paymentMethod = ($payroll->payment_method === 'bank') ? 'Bank Transfer' : 'Cash';
            Expense::updateOrCreate(
                ['voucher_no' => $voucherNo],
                [
                    'title' => "Staff Monthly Salary - {$payroll->staff_name} ({$payroll->month})",
                    'category' => 'Salaries & Advances',
                    'amount' => $payroll->net_salary,
                    'description' => "Monthly remuneration payout for {$payroll->staff_name} ({$payroll->designation}) for {$payroll->month}. Payment Method: " . ($payroll->payment_method === 'bank' ? 'School Bank Transfer' : 'Cash Counter'),
                    'created_by' => $userId,
                    'status' => 'disbursed',
                    'disbursed_at' => now(),
                    'disbursed_by' => $userId,
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $payroll->account_number,
                    'expense_type' => 'general',
                ]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => "All payrolls for {$month} marked as paid successfully and debited from respective accounts.",
        ]);
    }

    /**
     * Bonus & Incentive Details Report
     */
    public function bonusReport(Request $request)
    {
        $month = $request->query('month'); // optional e.g. 2026-08
        $staffType = $request->query('type', 'all');

        $query = Payroll::query()->where('total_bonuses', '>', 0);

        if ($month && $month !== 'all') {
            $query->where('month', $month);
        }

        if ($staffType !== 'all') {
            $query->where('staff_type', $staffType);
        }

        $payrolls = $query->orderBy('month', 'desc')->orderBy('staff_name', 'asc')->get();

        $itemized = [];
        $categories = [];

        foreach ($payrolls as $p) {
            $bonuses = is_array($p->bonuses) ? $p->bonuses : [];
            foreach ($bonuses as $b) {
                $title = trim($b['title'] ?? 'Special Bonus');
                $amt = (float) ($b['amount'] ?? 0);
                if ($amt <= 0) continue;

                $approvedBy = !empty($b['approved_by']) ? $b['approved_by'] : ($p->creator ? $p->creator->name : 'Executive Principal');

                $categories[$title] = ($categories[$title] ?? 0) + $amt;

                $itemized[] = [
                    'id' => $p->id . '_' . md5($title . $amt . $approvedBy),
                    'payroll_id' => $p->id,
                    'month' => $p->month,
                    'staff_id' => $p->staff_id,
                    'staff_type' => $p->staff_type,
                    'staff_name' => $p->staff_name,
                    'designation' => $p->designation,
                    'base_salary' => (float) $p->base_salary,
                    'bonus_title' => $title,
                    'bonus_amount' => $amt,
                    'approved_by' => $approvedBy,
                    'approved_at' => $b['approved_at'] ?? null,
                    'total_bonuses' => (float) $p->total_bonuses,
                    'net_salary' => (float) $p->net_salary,
                    'payment_method' => $p->payment_method,
                    'bank_name' => $p->bank_name,
                    'account_number' => $p->account_number,
                    'status' => $p->status,
                    'paid_at' => $p->paid_at,
                    'remarks' => $p->remarks,
                ];
            }
        }

        $totalBonusAmount = collect($itemized)->sum('bonus_amount');
        $uniqueStaffCount = collect($itemized)->pluck('staff_name')->unique()->count();
        $avgBonus = $uniqueStaffCount > 0 ? round($totalBonusAmount / $uniqueStaffCount, 2) : 0;

        return response()->json([
            'status' => 'success',
            'month' => $month,
            'stats' => [
                'total_bonus_amount' => round($totalBonusAmount, 2),
                'total_recipients' => $uniqueStaffCount,
                'total_awards_count' => count($itemized),
                'average_bonus' => $avgBonus,
                'by_category' => $categories,
            ],
            'bonuses' => $itemized,
        ]);
    }

    /**
     * Teacher / Staff Detailed Financial Ledger
     */
    public function teacherLedger(Request $request, $id)
    {
        $currentUser = $request->user();
        if ($currentUser && $currentUser->role === 'teacher') {
            $teacher = Teacher::where('user_id', $currentUser->id)->first();
            if (!$teacher || (int)$teacher->id !== (int)$id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. You can only view your own financial ledger.',
                ], 403);
            }
        }

        $staffType = $request->query('type', 'teacher');
        if ($staffType === 'teacher') {
            $staff = Teacher::with('user')->findOrFail($id);
        } else {
            $staff = StaffMember::findOrFail($id);
        }

        $payrolls = Payroll::where('staff_type', $staffType)
            ->where('staff_id', $id)
            ->orderBy('month', 'desc')
            ->get();

        $advanceRequests = \App\Models\AdvanceSalaryRequest::where('staff_type', $staffType)
            ->where('staff_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'staff' => $staff,
            'staff_type' => $staffType,
            'payrolls' => $payrolls,
            'advance_requests' => $advanceRequests,
        ]);
    }

    /**
     * Logged-in teacher's own financial ledger
     */
    public function myLedger(Request $request)
    {
        $currentUser = $request->user();
        $teacher = Teacher::where('user_id', $currentUser->id)->first();
        
        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'No faculty teacher profile found for current user.',
            ], 404);
        }

        $payrolls = Payroll::where('staff_type', 'teacher')
            ->where('staff_id', $teacher->id)
            ->orderBy('month', 'desc')
            ->get();

        $advanceRequests = \App\Models\AdvanceSalaryRequest::where('staff_type', 'teacher')
            ->where('staff_id', $teacher->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'staff' => $teacher,
            'staff_type' => 'teacher',
            'payrolls' => $payrolls,
            'advance_requests' => $advanceRequests,
        ]);
    }

    /**
     * Get specific payslip detail
     */
    public function show(Request $request, $id)
    {
        $payroll = Payroll::findOrFail($id);
        $payroll->staff_profile = $payroll->staff;

        $currentUser = $request->user();
        if ($currentUser && $currentUser->role === 'teacher') {
            $teacher = Teacher::where('user_id', $currentUser->id)->first();
            if (!$teacher || $payroll->staff_type !== 'teacher' || (int)$payroll->staff_id !== (int)$teacher->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. You can only view your own salary slip.',
                ], 403);
            }
        } elseif ($currentUser && !in_array($currentUser->role, ['accountant', 'hr_accountant', 'hr', 'director', 'principal', 'super_admin', 'admin', 'chairman'])) {
            $staff = StaffMember::where('user_id', $currentUser->id)->first();
            if (!$staff || $payroll->staff_type !== 'non_teaching' || (int)$payroll->staff_id !== (int)$staff->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. You can only view your own salary slip.',
                ], 403);
            }
        }

        return response()->json([
            'status' => 'success',
            'payroll' => $payroll,
        ]);
    }

    /**
     * Helper to compute attendance counts for a date range
     */
    private function calculateAttendanceStats($staffType, $staffId, $startDate, $endDate)
    {
        $attendances = StaffAttendance::where('staff_type', $staffType)
            ->where('staff_id', $staffId)
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        return [
            'present_count' => $attendances->where('status', 'present')->count(),
            'late_count' => $attendances->where('status', 'late')->count(),
            'absent_count' => $attendances->where('status', 'absent')->count(),
            'leave_count' => $attendances->where('status', 'leave')->count(),
        ];
    }
}
