<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\GrantController;
use App\Http\Controllers\Api\ChequeController;
use App\Http\Controllers\Api\BankStatementController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DirectorEquityController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\StaffMemberController;
use App\Http\Controllers\Api\StaffAttendanceController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\AdvanceSalaryController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentAttendanceController;
use App\Http\Controllers\Api\BiometricDeviceController;
// Public Auth Routes (Rate Limited for Enterprise Security & Brute-force Protection)
Route::get('/login', fn() => response()->json(['message' => 'Unauthenticated.'], 401))->name('login');
Route::middleware('throttle:6,1')->post('/auth/request-otp', [AuthController::class, 'requestOtp']);
Route::middleware('throttle:10,1')->post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);

// Protected Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    // Current User & Profile
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Update FCM Mobile Push Token
    Route::post('/auth/fcm-token', function (Request $request) {
        $request->validate(['fcm_token' => 'required|string']);
        $request->user()->update(['fcm_token' => $request->fcm_token]);
        return response()->json(['message' => 'FCM token registered successfully.']);
    });

    // Secure Logout & Token Invalidation
    Route::post('/auth/logout', function (Request $request) {
        $user = $request->user();
        $user->update(['fcm_token' => null]);
        $user->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out securely.']);
    });

    // Live 3-Way Financial Summary, Digital Bank Statement & Cash Drawer Statement
    Route::get('/financial-summary', [BankStatementController::class, 'financialSummary']);
    Route::get('/bank-statement/summary', [BankStatementController::class, 'financialSummary']);
    Route::get('/bank/statement/export-pdf', [BankStatementController::class, 'exportPdf']);
    Route::get('/bank/statement', [BankStatementController::class, 'statement']);
    Route::get('/cash-drawer/statement', [BankStatementController::class, 'cashDrawerStatement']);
    Route::get('/cash-inflows', [BankStatementController::class, 'getCashInflows']);
    Route::post('/cash-inflows', [BankStatementController::class, 'storeCashInflow']);

    // Expenses (Direct Posting, PDF Export, Stats & Overview)
    Route::get('/expenses/export-pdf', [ExpenseController::class, 'exportPdf']);
    Route::get('/expenses/overview', [ExpenseController::class, 'overview']);
    Route::get('/expenses/stats', [ExpenseController::class, 'stats']);
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses/{id}', [ExpenseController::class, 'show']);

    // Government Grants & Tranches (Income)
    Route::get('/grants', [GrantController::class, 'index']);
    Route::post('/grants', [GrantController::class, 'store']);
    Route::delete('/grants/{id}', [GrantController::class, 'destroy']);

    // Cheques Management & Clearance
    Route::get('/cheques/export-pdf', [ChequeController::class, 'exportPdf']);
    Route::get('/cheques', [ChequeController::class, 'index']);
    Route::post('/cheques', [ChequeController::class, 'store']);
    Route::patch('/cheques/{id}/cash-out', [ChequeController::class, 'cashOut']);
    Route::delete('/cheques/{id}', [ChequeController::class, 'destroy']);

    // Financial Expense Categories (CRUD)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::patch('/categories/{id}/toggle-status', [CategoryController::class, 'toggleStatus']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Director & Partner Equity, Capital Ledgers & Profit Distributions (Director/Admin only)
    Route::middleware('role:chairman,director,super_admin,admin')->group(function () {
        Route::get('/directors', [DirectorEquityController::class, 'index']);
        Route::get('/directors/pnl-report', [DirectorEquityController::class, 'pnlReport']);
        Route::get('/directors/expenses-report', [DirectorEquityController::class, 'directorExpensesReport']);
        Route::get('/directors/expenses-report/export-pdf', [DirectorEquityController::class, 'exportDirectorExpensesPdf']);
        Route::post('/directors', [DirectorEquityController::class, 'storeDirector']);
        Route::put('/directors/{id}', [DirectorEquityController::class, 'updateDirector']);
        Route::get('/directors/{id}/statement', [DirectorEquityController::class, 'statement']);
        Route::get('/directors/{id}/export-pdf', [DirectorEquityController::class, 'exportPdf']);
        Route::post('/directors/transactions', [DirectorEquityController::class, 'storeTransaction']);
        Route::post('/directors/capital-expenses', [DirectorEquityController::class, 'storeCapitalExpense']);
        Route::post('/directors/distribute-profit', [DirectorEquityController::class, 'distributeProfit']);
    });

    // Super Admin Management (Master User Creation & System Governance)
    Route::middleware('role:super_admin,admin')->group(function () {
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::post('/admin/users', [AdminController::class, 'createUser']);
        Route::put('/admin/users/{id}', [AdminController::class, 'updateUser']);
        Route::patch('/admin/users/{id}/toggle-status', [AdminController::class, 'toggleStatus']);
        Route::delete('/admin/users/{id}', [AdminController::class, 'deleteUser']);
        Route::get('/admin/system-stats', [AdminController::class, 'systemStats']);
    });

    // 1-on-1 Staff Realtime Intercom & Messaging
    Route::get('/chat/contacts', [ChatController::class, 'contacts']);
    Route::get('/chat/messages/{recipient_id}', [ChatController::class, 'messages']);
    Route::post('/chat/messages', [ChatController::class, 'sendMessage']);

    // Staff Task Delegation Desk & Discussion
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);
    Route::post('/tasks/{id}/comments', [TaskController::class, 'addComment']);

    // Faculty & Teacher Management (Principal, HR, Admin)
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::post('/teachers', [TeacherController::class, 'store']);
    Route::get('/teachers/{id}', [TeacherController::class, 'show']);
    Route::post('/teachers/{id}', [TeacherController::class, 'update']);
    Route::post('/teachers/{id}/status', [TeacherController::class, 'updateStatus']);
    Route::post('/teachers/{id}/toggle-status', [TeacherController::class, 'toggleStatus']);
    Route::delete('/teachers/{id}', [TeacherController::class, 'destroy']);
    Route::get('/teacher/me', [TeacherController::class, 'getProfile']);
    Route::post('/teacher/me', [TeacherController::class, 'updateProfile']);

    // Non-Teaching Staff Management (HR, Principal, Admin)
    Route::get('/staff-members', [StaffMemberController::class, 'index']);
    Route::post('/staff-members', [StaffMemberController::class, 'store']);
    Route::get('/staff-members/{id}', [StaffMemberController::class, 'show']);
    Route::post('/staff-members/{id}', [StaffMemberController::class, 'update']);
    Route::post('/staff-members/{id}/status', [StaffMemberController::class, 'updateStatus']);
    Route::delete('/staff-members/{id}', [StaffMemberController::class, 'destroy']);

    // Staff Attendance Desk (HR, Principal, Admin)
    Route::get('/attendance/daily', [StaffAttendanceController::class, 'getDaily']);
    Route::post('/attendance/mark', [StaffAttendanceController::class, 'mark']);
    Route::post('/attendance/mark-all-present', [StaffAttendanceController::class, 'markAllPresent']);
    Route::get('/attendance/summary', [StaffAttendanceController::class, 'getMonthlySummary']);

    // Staff Payroll & Payslips (HR, Principal, Accountant, Admin)
    Route::get('/payroll', [PayrollController::class, 'index']);
    Route::get('/payroll/reports/bonuses', [PayrollController::class, 'bonusReport']);
    Route::post('/payroll/mark-all-paid', [PayrollController::class, 'markAllPaid']);
    Route::post('/payroll/batch-pay', [PayrollController::class, 'markAllPaid']);
    Route::post('/payroll/{id}/mark-paid', [PayrollController::class, 'markPaid']);
    Route::post('/payroll/{id}/pay', [PayrollController::class, 'markPaid']);
    Route::put('/payroll/{id}', [PayrollController::class, 'update']);
    Route::get('/payroll/{id}', [PayrollController::class, 'show']);
    Route::get('/teachers/{id}/ledger', [PayrollController::class, 'teacherLedger']);
    Route::get('/teacher/my-ledger', [PayrollController::class, 'myLedger']);

    // Advance Salary Requests & Executive Approvals (Teacher, Principal, Directors)
    Route::get('/advance-salary-requests', [AdvanceSalaryController::class, 'index']);
    Route::post('/advance-salary-requests', [AdvanceSalaryController::class, 'store']);
    Route::get('/advance-salary-requests/my', [AdvanceSalaryController::class, 'myRequests']);
    Route::post('/advance-salary-requests/{id}/approve', [AdvanceSalaryController::class, 'approve']);
    Route::post('/advance-salary-requests/{id}/reject', [AdvanceSalaryController::class, 'reject']);
    Route::post('/advance-salary-requests/{id}/disburse', [AdvanceSalaryController::class, 'disburse']);

    // Staff Leave Applications & Principal Approvals (Teacher, Staff, Principal, HR)
    Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
    Route::get('/leave-requests/my', [LeaveRequestController::class, 'myLeaves']);
    Route::post('/leave-requests/{id}/approve', [LeaveRequestController::class, 'approve']);
    Route::post('/leave-requests/{id}/reject', [LeaveRequestController::class, 'reject']);

    // Students Management & Government Register Import (HR, Principal, Admin)
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/stats', [StudentController::class, 'stats']);
    Route::get('/students/sessions', [StudentController::class, 'sessions']);
    Route::get('/students/sections', [StudentController::class, 'getSections']);
    Route::post('/students/sections', [StudentController::class, 'storeSection']);
    Route::delete('/students/sections/{id}', [StudentController::class, 'destroySection']);
    Route::post('/students/bulk-assign-section', [StudentController::class, 'bulkAssignSection']);
    Route::post('/students/upload-chunk', [StudentController::class, 'uploadChunk']);
    Route::get('/students/{id}', [StudentController::class, 'show']);

    // Student Daily Attendance (Attendance Officer, HR, Principal, Admin, Teacher)
    Route::get('/student-attendances/class-sheet', [StudentAttendanceController::class, 'classSheet']);
    Route::post('/student-attendances/submit-class', [StudentAttendanceController::class, 'submitClass']);
    Route::get('/student-attendances/daily-summary', [StudentAttendanceController::class, 'dailySummary']);
    Route::get('/student-attendances/monthly-register', [StudentAttendanceController::class, 'monthlyRegister']);
    Route::get('/student-attendances/daily-report-pdf', [StudentAttendanceController::class, 'dailyReportPdf']);
    Route::get('/student-attendances/teachers-list', [StudentAttendanceController::class, 'teachersList']);
    Route::post('/student-attendances/{id}/follow-up', [StudentAttendanceController::class, 'saveFollowUp']);
    Route::get('/student-attendances/whatsapp-template', [StudentAttendanceController::class, 'getWhatsAppTemplate']);
    Route::post('/student-attendances/broadcast-whatsapp', [StudentAttendanceController::class, 'broadcastAbsenteeWhatsApp']);

    // Biometric Machines Desk (device user registry + two-way name sync)
    Route::get('/bio-machines', [BiometricDeviceController::class, 'index']);
    Route::get('/bio-machines/{sn}/users', [BiometricDeviceController::class, 'users']);
    Route::post('/bio-machines/{sn}/sync-users', [BiometricDeviceController::class, 'syncUsers']);
    Route::post('/bio-machines/{sn}/users/{pin}/name', [BiometricDeviceController::class, 'saveName']);
    Route::post('/bio-machines/{sn}/users/{pin}/update-info', [BiometricDeviceController::class, 'updateInfo']);
    Route::post('/student-attendances/{id}/send-whatsapp', [StudentAttendanceController::class, 'sendSingleAbsenteeWhatsApp']);
});

