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
});
