<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Director;
use App\Models\DirectorTransaction;
use App\Models\ProfitDistribution;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\Category;
use App\Models\User;
use App\Services\FcmService;
use App\Services\DocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DirectorEquityController extends Controller
{
    /**
     * List all Directors with equity shares, capital, profit, withdrawals, and live balance.
     */
    public function index(Request $request)
    {
        // Auto-sync active Chairman & Director users into director records
        $executiveUsers = User::whereIn('role', ['chairman', 'director'])->where('is_active', true)->get();
        if ($executiveUsers->isNotEmpty()) {
            $defaultShare = round(100 / $executiveUsers->count(), 2);
            foreach ($executiveUsers as $execUser) {
                $existing = Director::where('user_id', $execUser->id)->orWhere('email', $execUser->email)->first();
                if (!$existing) {
                    Director::create([
                        'user_id' => $execUser->id,
                        'name' => $execUser->name,
                        'email' => $execUser->email,
                        'share_percentage' => $defaultShare,
                        'status' => 'active',
                    ]);
                }
            }
        }

        $rawDirectors = Director::with(['user:id,name,email,role'])
            ->where('status', 'active')
            ->orderBy('id', 'asc')
            ->get();

        $directorsData = [];
        $totalNetCapitalAll = 0.0;
        $totalCapitalInjectedAll = 0.0;
        $totalExpensesPaidAll = 0.0;
        $totalDirectInjectedAll = 0.0;
        $totalProfitAll = 0.0;
        $totalWithdrawnAll = 0.0;

        foreach ($rawDirectors as $director) {
            $directInjected = (float) $director->transactions()->where('type', 'capital_injection')->sum('amount');
            $expensesPaid = (float) $director->transactions()->where('type', 'capital_expense')->sum('amount');
            $withdrawn = (float) $director->transactions()->where('type', 'withdrawal')->sum('amount');
            $profit = (float) $director->transactions()->where('type', 'profit_credit')->sum('amount');
            
            // Total Capital = Direct Cash Injections + Out-of-pocket Expenses Paid by Director
            $totalInjected = $directInjected + $expensesPaid;
            $netCapital = max(0, ($totalInjected + $profit) - $withdrawn);
            $liveBalance = $netCapital;

            $totalNetCapitalAll += $netCapital;
            $totalCapitalInjectedAll += $totalInjected;
            $totalExpensesPaidAll += $expensesPaid;
            $totalDirectInjectedAll += $directInjected;
            $totalProfitAll += $profit;
            $totalWithdrawnAll += $withdrawn;

            $directorsData[] = [
                'id' => $director->id,
                'user_id' => $director->user_id,
                'name' => $director->name,
                'cnic' => $director->cnic,
                'phone' => $director->phone,
                'email' => $director->email,
                'share_percentage' => (float) $director->share_percentage,
                'expected_monthly_contribution' => (float) ($director->expected_monthly_contribution ?? 0.0),
                'bank_name' => $director->bank_name,
                'bank_account_no' => $director->bank_account_no,
                'bank_iban' => $director->bank_iban,
                'status' => $director->status,
                'net_capital' => $netCapital,
                'total_capital_injected' => $totalInjected,
                'total_expenses_paid' => $expensesPaid,
                'total_direct_injected' => $directInjected,
                'total_capital_consumed' => $expensesPaid, // Aliased for backward compatibility
                'total_profit_earned' => $profit,
                'total_withdrawn' => $withdrawn,
                'live_balance' => $liveBalance,
            ];
        }

        // Calculate dynamic capital share percentage based on Net Capital while preserving configured agreed profit share
        $directors = array_map(function ($d) use ($totalNetCapitalAll) {
            $dynamicShare = $totalNetCapitalAll > 0 
                ? round(($d['net_capital'] / $totalNetCapitalAll) * 100, 2) 
                : 0.00;
            $d['capital_share_percentage'] = $dynamicShare;
            return $d;
        }, $directorsData);

        $totalSharePercentage = (float) array_sum(array_column($directors, 'share_percentage'));
        $totalExpectedContributions = (float) array_sum(array_column($directors, 'expected_monthly_contribution'));
        $totalUndrawnPool = $totalNetCapitalAll;

        // Recent Batches
        $recentDistributions = ProfitDistribution::with('distributor:id,name')->latest()->take(6)->get();

        return response()->json([
            'data' => $directors,
            'summary' => [
                'total_directors' => count($directors),
                'total_net_capital' => $totalNetCapitalAll,
                'total_capital_injected' => $totalCapitalInjectedAll,
                'total_expenses_paid' => $totalExpensesPaidAll,
                'total_direct_injected' => $totalDirectInjectedAll,
                'total_capital_consumed' => $totalExpensesPaidAll,
                'total_profit_allocated' => $totalProfitAll,
                'total_withdrawn' => $totalWithdrawnAll,
                'total_undrawn_pool' => $totalUndrawnPool,
                'total_share_percentage' => $totalSharePercentage,
                'total_expected_contributions' => $totalExpectedContributions,
            ],
            'recent_distributions' => $recentDistributions,
        ]);
    }

    /**
     * Add new Director profile.
     */
    public function storeDirector(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'share_percentage' => 'required|numeric|min:0|max:100',
            'expected_monthly_contribution' => 'nullable|numeric|min:0',
            'cnic' => 'nullable|string|max:25',
            'phone' => 'nullable|string|max:25',
            'email' => 'nullable|email|max:255',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_no' => 'nullable|string|max:50',
            'bank_iban' => 'nullable|string|max:50',
        ]);

        $userId = null;
        if ($request->filled('email')) {
            $existingUser = User::where('email', $request->email)->first();
            if ($existingUser) {
                $userId = $existingUser->id;
            } else {
                $newUser = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'role' => 'director',
                    'is_active' => true,
                ]);
                $userId = $newUser->id;
            }
        }

        $director = Director::create([
            'user_id' => $userId,
            'name' => $request->name,
            'share_percentage' => $request->share_percentage,
            'expected_monthly_contribution' => $request->expected_monthly_contribution ?? 0,
            'cnic' => $request->cnic,
            'phone' => $request->phone,
            'email' => $request->email,
            'bank_name' => $request->bank_name,
            'bank_account_no' => $request->bank_account_no,
            'bank_iban' => $request->bank_iban,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Director registered successfully.',
            'data' => $director,
        ], 201);
    }

    /**
     * Update Director profile & share percentage.
     */
    public function updateDirector(Request $request, $id)
    {
        $director = Director::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'share_percentage' => 'required|numeric|min:0|max:100',
            'expected_monthly_contribution' => 'nullable|numeric|min:0',
            'cnic' => 'nullable|string|max:25',
            'phone' => 'nullable|string|max:25',
            'email' => 'nullable|email|max:255',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_no' => 'nullable|string|max:50',
            'bank_iban' => 'nullable|string|max:50',
            'status' => 'nullable|in:active,inactive',
        ]);

        $userId = $director->user_id;
        if ($request->filled('email')) {
            $existingUser = User::where('email', $request->email)->first();
            if ($existingUser) {
                $userId = $existingUser->id;
            } else {
                $newUser = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'role' => 'director',
                    'is_active' => true,
                ]);
                $userId = $newUser->id;
            }
        }

        $updateData = $request->only([
            'name', 'share_percentage', 'expected_monthly_contribution', 'cnic', 'phone', 'email',
            'bank_name', 'bank_account_no', 'bank_iban', 'status'
        ]);
        $updateData['user_id'] = $userId;
        $director->update($updateData);

        return response()->json([
            'message' => 'Director updated successfully.',
            'data' => $director,
        ]);
    }

    /**
     * Itemized Chronological Ledger for a specific Director (with Brought Forward Opening Balance).
     */
    public function statement(Request $request, $id)
    {
        $director = Director::findOrFail($id);
        $month = $request->get('month'); // e.g. "2026-08"

        // Single indexed aggregate query for this partner's net capital
        $lifetimeAggregates = DB::table('director_transactions')
            ->selectRaw("
                SUM(CASE WHEN type = 'capital_injection' THEN amount ELSE 0 END) as total_direct_injected,
                SUM(CASE WHEN type = 'capital_expense' THEN amount ELSE 0 END) as total_expenses_paid,
                SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_withdrawn,
                SUM(CASE WHEN type = 'profit_credit' THEN amount ELSE 0 END) as total_profit
            ")
            ->where('director_id', $id)
            ->first();

        $lifetimeDirectInjected = (float) ($lifetimeAggregates->total_direct_injected ?? 0);
        $lifetimeExpensesPaid = (float) ($lifetimeAggregates->total_expenses_paid ?? 0);
        $lifetimeInjected = $lifetimeDirectInjected + $lifetimeExpensesPaid;
        $lifetimeWithdrawn = (float) ($lifetimeAggregates->total_withdrawn ?? 0);
        $lifetimeProfit = (float) ($lifetimeAggregates->total_profit ?? 0);
        $lifetimeNetCapital = max(0, ($lifetimeInjected + $lifetimeProfit) - $lifetimeWithdrawn);
        $lifetimeLiveBalance = $lifetimeNetCapital;

        $txQuery = $director->transactions()->with(['creator:id,name,role', 'expense']);

        $openingBalance = 0.0;
        if ($month) {
            $parsedDate = Carbon::parse($month . '-01');
            $startOfMonth = $parsedDate->copy()->startOfMonth()->toDateString();
            $endOfMonth = $parsedDate->copy()->endOfMonth()->toDateString();

            // 1 fast indexed aggregate query for opening balance prior to this month
            $priorAggregates = DB::table('director_transactions')
                ->selectRaw("
                    SUM(CASE WHEN type IN ('capital_injection', 'capital_expense', 'profit_credit') THEN amount ELSE 0 END) as total_credits,
                    SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_debits
                ")
                ->where('director_id', $id)
                ->where('date', '<', $startOfMonth)
                ->first();

            $openingBalance = max(0, (float) ($priorAggregates->total_credits ?? 0) - (float) ($priorAggregates->total_debits ?? 0));

            $txQuery->whereBetween('date', [$startOfMonth, $endOfMonth]);
        }

        $transactions = $txQuery->orderBy('date', 'asc')->orderBy('id', 'asc')->get();

        $runningBalance = $openingBalance;
        $statement = [];
        $usageLedger = [];
        $drawingsLedger = [];
        $injectionsLedger = [];

        // Prepend Brought Forward Opening Balance if viewing a month with prior history
        if ($month && $openingBalance > 0) {
            $prevMonthName = $parsedDate->copy()->subMonth()->format('F Y');
            $statement[] = [
                'id' => 'opening_balance_dir',
                'type' => 'opening_balance',
                'title' => "Opening Balance (Brought Forward from {$prevMonthName})",
                'reference_no' => 'B/F Balance',
                'date' => $startOfMonth,
                'payment_method' => 'Brought Forward',
                'credit' => $openingBalance,
                'debit' => 0.0,
                'running_balance' => $openingBalance,
                'notes' => 'Carried forward from preceding month',
                'slip_url' => null,
                'creator_name' => 'System',
            ];
        }

        $periodUsage = 0.0;
        $periodDrawings = 0.0;
        $periodInjections = 0.0;
        $periodProfitShares = 0.0;

        foreach ($transactions as $tx) {
            $isCredit = in_array($tx->type, ['capital_injection', 'capital_expense', 'profit_credit']);
            $amount = (float) $tx->amount;

            if ($isCredit) {
                $runningBalance += $amount;
            } else {
                $runningBalance -= $amount;
            }

            if ($tx->type === 'capital_expense') {
                $periodUsage += $amount;
            } elseif ($tx->type === 'withdrawal') {
                $periodDrawings += $amount;
            } elseif ($tx->type === 'capital_injection') {
                $periodInjections += $amount;
            } elseif ($tx->type === 'profit_credit') {
                $periodProfitShares += $amount;
            }

            $typeLabel = match ($tx->type) {
                'capital_injection' => 'Direct Capital Deposit',
                'capital_expense' => 'Director Paid Expense (Capital Contribution)',
                'profit_credit' => 'Monthly Profit Share',
                'withdrawal' => 'Director Drawing / Withdrawal',
                default => ucfirst($tx->type),
            };

            $categoryName = $tx->expense?->category ?? null;

            $entry = [
                'id' => $tx->id,
                'type' => $tx->type,
                'type_label' => $typeLabel,
                'title' => $tx->notes ?: $typeLabel,
                'category_name' => $categoryName,
                'reference_no' => $tx->reference_no ?? 'Direct Txn',
                'date' => $tx->date->format('Y-m-d'),
                'payment_method' => $tx->payment_method,
                'period_month' => $tx->period_month,
                'credit' => $isCredit ? $amount : 0.0,
                'debit' => !$isCredit ? $amount : 0.0,
                'running_balance' => max(0, $runningBalance),
                'notes' => $tx->notes,
                'slip_url' => $tx->slip_url ? asset('storage/' . $tx->slip_url) : null,
                'creator_name' => $tx->creator->name ?? 'Director',
            ];

            $statement[] = $entry;

            if ($tx->type === 'capital_expense') {
                $usageLedger[] = $entry;
            } elseif ($tx->type === 'withdrawal') {
                $drawingsLedger[] = $entry;
            } elseif ($tx->type === 'capital_injection') {
                $injectionsLedger[] = $entry;
            }
        }

        $periodCredits = $periodInjections + $periodUsage + $periodProfitShares;
        $periodDebits = $periodDrawings;
        $closingBalance = max(0, ($openingBalance + $periodCredits) - $periodDebits);

        return response()->json([
            'director' => [
                'id' => $director->id,
                'name' => $director->name,
                'email' => $director->email,
                'phone' => $director->phone,
                'cnic' => $director->cnic,
                'net_capital' => $lifetimeNetCapital,
                'total_capital_injected' => $lifetimeInjected,
                'total_expenses_paid' => $lifetimeExpensesPaid,
                'total_direct_injected' => $lifetimeDirectInjected,
                'total_capital_consumed' => $lifetimeExpensesPaid,
                'total_withdrawn' => $lifetimeWithdrawn,
                'live_balance' => $lifetimeLiveBalance,
                'bank_name' => $director->bank_name,
                'bank_account_no' => $director->bank_account_no,
            ],
            'month' => $month,
            'summary' => [
                'opening_balance' => $openingBalance,
                'current_balance' => $closingBalance,
                'closing_balance' => $closingBalance,
                'total_credits' => $periodCredits,
                'total_debits' => $periodDebits,
                'total_usage' => $periodUsage,
                'total_drawings' => $periodDrawings,
                'total_injections' => $periodInjections,
                'total_profit_shares' => $periodProfitShares,
            ],
            'data' => $statement,
            'usage_ledger' => $usageLedger,
            'drawings_ledger' => $drawingsLedger,
            'injections_ledger' => $injectionsLedger,
            'opening_balance' => $openingBalance,
            'current_balance' => $closingBalance,
            'total_credits' => $periodCredits,
            'total_debits' => $periodDebits,
        ]);
    }

    /**
     * Record Capital Injection or Director Withdrawal + Notify All Directors.
     */
    public function storeTransaction(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['chairman', 'director', 'super_admin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized. Only executive directors or administration can record transactions.'], 403);
        }

        if ($user->role === 'director') {
            if ($request->type !== 'capital_injection') {
                return response()->json(['message' => 'Unauthorized. Only the Chairman can record capital withdrawals.'], 403);
            }
            $director = Director::where('user_id', $user->id)->orWhere('email', $user->email)->first();
            if (!$director) {
                return response()->json(['message' => 'Director profile not found for the logged in user.'], 404);
            }
        } else {
            $request->validate([
                'director_id' => 'required|exists:directors,id',
            ]);
            $director = Director::findOrFail($request->director_id);
        }

        $request->validate([
            'type' => 'required|in:capital_injection,withdrawal',
            'amount' => 'required|numeric|min:1|max:999999999',
            'date' => 'required|date',
            'payment_method' => 'required|string|max:100',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'slip' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        // Overdraw protection for withdrawals
        if ($request->type === 'withdrawal') {
            $liveBalance = $director->live_balance;
            if ($request->amount > $liveBalance) {
                return response()->json([
                    'message' => "Insufficient undrawn funds. Director {$director->name} has Rs. " . number_format($liveBalance, 0) . " available, but requested Rs. " . number_format($request->amount, 0) . ".",
                ], 422);
            }
        }

        $slipPath = null;
        if ($request->hasFile('slip')) {
            $slipPath = $request->file('slip')->store('director_slips', 'public');
        }

        $transaction = DirectorTransaction::create([
            'director_id' => $director->id,
            'type' => $request->type,
            'amount' => $request->amount,
            'date' => $request->date,
            'payment_method' => $request->payment_method,
            'reference_no' => $request->reference_no,
            'slip_url' => $slipPath,
            'notes' => $request->notes,
            'created_by' => $user->id,
        ]);

        // Send instant push notification to all directors
        FcmService::notifyDirectorTransaction($transaction);

        return response()->json([
            'message' => ($request->type === 'capital_injection')
                ? "Direct capital injection of Rs. " . number_format($request->amount) . " credited to {$director->name}."
                : "Withdrawal of Rs. " . number_format($request->amount) . " debited from {$director->name}.",
            'data' => $transaction,
        ], 201);
    }

    /**
     * Record an Expense paid directly by a Director out of pocket.
     * Directly credits the Director's Capital Contribution and updates their dynamic equity.
     */
    public function storeCapitalExpense(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['chairman', 'director', 'super_admin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized. Only executive directors or administration can record director expenses.'], 403);
        }

        if ($user->role === 'director') {
            $director = Director::where('user_id', $user->id)->orWhere('email', $user->email)->first();
            if (!$director) {
                return response()->json(['message' => 'Director profile not found for the logged in user.'], 404);
            }
        } else {
            $request->validate([
                'director_id' => 'required|exists:directors,id',
            ]);
            $director = Director::findOrFail($request->director_id);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1|max:999999999',
            'date' => 'required|date',
            'category_id' => 'nullable',
            'payment_method' => 'nullable|string|max:100',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        DB::beginTransaction();
        try {
            // Resolve or auto-create Category
            $categoryName = 'Capital Expenditure';
            if ($request->filled('category_id')) {
                $cat = is_numeric($request->category_id) ? \App\Models\Category::find($request->category_id) : null;
                if ($cat) {
                    $categoryName = $cat->name;
                } elseif (is_string($request->category_id) && trim($request->category_id) !== '') {
                    $categoryName = trim($request->category_id);
                }
            } elseif ($request->filled('category') && trim($request->category) !== '') {
                $categoryName = trim($request->category);
            }

            // Ensure the category is persisted in the categories table for future selection
            if (!empty($categoryName) && $categoryName !== 'Capital Expenditure') {
                \App\Models\Category::firstOrCreate(
                    ['name' => $categoryName],
                    ['icon' => '🏷️', 'description' => 'Director Capital Expenditure']
                );
            }

            $tierInfo = Expense::resolveTier((float) $request->amount);
            $voucherNo = 'DIR-' . strtoupper(uniqid());
            $refNo = $request->reference_no ?: $voucherNo;

            // Create Expense record marked specifically as 'director'
            $expense = Expense::create([
                'voucher_no' => $voucherNo,
                'title' => $request->title,
                'category' => $categoryName,
                'description' => $request->notes ?: $request->title,
                'amount' => $request->amount,
                'amount_tier' => $tierInfo['tier'],
                'tier_color' => $tierInfo['color'],
                'expense_type' => 'director',
                'director_id' => $director->id,
                'payment_method' => $request->payment_method ?? 'Director Personal Funds',
                'payment_reference' => $refNo,
                'receipt_url' => $receiptPath,
                'status' => 'disbursed',
                'created_by' => $user->id,
                'disbursed_by' => $user->id,
                'disbursed_at' => $request->date ?? now(),
            ]);

            // Create DirectorTransaction record which CREDITS director capital
            $transaction = DirectorTransaction::create([
                'director_id' => $director->id,
                'type' => 'capital_expense',
                'amount' => $request->amount,
                'date' => $request->date,
                'payment_method' => $request->payment_method ?? 'Director Personal Funds',
                'reference_no' => $refNo,
                'expense_id' => $expense->id,
                'slip_url' => $receiptPath,
                'notes' => $request->title . ($request->notes ? " - {$request->notes}" : ''),
                'created_by' => $user->id,
            ]);

            DB::commit();

            // Broadcast push notification to all Directors
            FcmService::notifyDirectorTransaction($transaction);

            return response()->json([
                'message' => "Director expense of Rs. " . number_format($request->amount) . " paid by {$director->name} recorded. Capital increased.",
                'data' => $transaction,
                'expense' => $expense,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            if ($receiptPath && Storage::disk('public')->exists($receiptPath)) {
                Storage::disk('public')->delete($receiptPath);
            }
            return response()->json(['message' => 'Failed to record capital expense: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Execute Month-End Profit Distribution Batch with Partner Profit Shares and Shortfall Settlements.
     */
    public function distributeProfit(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['chairman', 'super_admin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized. Only the Chairman can execute profit distributions.'], 403);
        }

        $request->validate([
            'period_month' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'total_profit_pool' => 'required|numeric|min:1|max:999999999',
            'date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'expected_contributions' => 'nullable|array',
            'settlement_transfers' => 'nullable|array',
            'settlement_transfers.*.from_director_id' => 'required_with:settlement_transfers|exists:directors,id',
            'settlement_transfers.*.to_director_id' => 'required_with:settlement_transfers|exists:directors,id',
            'settlement_transfers.*.amount' => 'required_with:settlement_transfers|numeric|min:0.01',
            'settlement_transfers.*.notes' => 'nullable|string|max:255',
        ]);

        $periodMonth = $request->period_month;
        $totalPool = (float) $request->total_profit_pool;

        $currentMonth = Carbon::now()->format('Y-m');
        if ($periodMonth >= $currentMonth) {
            return response()->json([
                'message' => 'Profit can only be disbursed for previous months that have concluded (prior to ' . Carbon::now()->format('F Y') . ').',
            ], 422);
        }

        // Check if this month's profit was already distributed
        $existing = ProfitDistribution::where('period_month', $periodMonth)->first();
        if ($existing) {
            return response()->json([
                'message' => "Profit for {$periodMonth} was already distributed on {$existing->created_at->format('d-M-Y')}. To re-distribute, please contact administration.",
            ], 422);
        }

        $directors = Director::where('status', 'active')->orderBy('id')->get();
        if ($directors->isEmpty()) {
            return response()->json(['message' => 'No active directors found.'], 422);
        }

        // Validate that total profit share percentages sum to 100%
        $totalSharePct = (float) $directors->sum('share_percentage');
        if (abs($totalSharePct - 100.00) > 0.05) {
            return response()->json([
                'message' => "Total active partner profit share percentages must sum to 100.00%. Current configured sum is {$totalSharePct}%. Please adjust partner shares first.",
            ], 422);
        }

        // Save updated expected contributions if provided
        if ($request->has('expected_contributions') && is_array($request->expected_contributions)) {
            foreach ($request->expected_contributions as $dirId => $expAmt) {
                Director::where('id', $dirId)->update(['expected_monthly_contribution' => max(0, (float) $expAmt)]);
            }
            // Refresh directors with updated values
            $directors = Director::where('status', 'active')->orderBy('id')->get();
        }

        // Calculate base profit for each director based on configured percentage
        $directorBaseProfits = [];
        foreach ($directors as $director) {
            $directorBaseProfits[$director->id] = round($totalPool * ($director->share_percentage / 100), 2);
        }

        // Process settlement transfers
        $settlementTransfers = $request->input('settlement_transfers', []);
        $transfersOutByDirector = [];
        $transfersInByDirector = [];

        foreach ($settlementTransfers as $st) {
            $fromId = (int) $st['from_director_id'];
            $toId = (int) $st['to_director_id'];
            $amount = (float) $st['amount'];

            if ($fromId === $toId) {
                return response()->json(['message' => 'Transfer donor and recipient partner cannot be the same.'], 422);
            }

            $transfersOutByDirector[$fromId] = ($transfersOutByDirector[$fromId] ?? 0.0) + $amount;
            $transfersInByDirector[$toId] = ($transfersInByDirector[$toId] ?? 0.0) + $amount;
        }

        // Validate negative payout protection: transfers_out <= base_profit
        foreach ($transfersOutByDirector as $fromId => $totalOut) {
            $baseProf = $directorBaseProfits[$fromId] ?? 0.0;
            $dirName = $directors->firstWhere('id', $fromId)?->name ?? "Partner #{$fromId}";
            if ($totalOut > $baseProf + 0.01) {
                return response()->json([
                    'message' => "Transferred settlement from {$dirName} (Rs. " . number_format($totalOut) . ") exceeds their earned base profit share of Rs. " . number_format($baseProf) . ".",
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Build detailed snapshot for immutable historical record
            $breakdownList = [];
            foreach ($directors as $director) {
                $base = $directorBaseProfits[$director->id] ?? 0.0;
                $out = $transfersOutByDirector[$director->id] ?? 0.0;
                $in = $transfersInByDirector[$director->id] ?? 0.0;
                $netCash = max(0, $base - $out);

                $breakdownList[] = [
                    'director_id' => $director->id,
                    'director_name' => $director->name,
                    'share_percentage' => (float) $director->share_percentage,
                    'expected_monthly_contribution' => (float) ($director->expected_monthly_contribution ?? 0.0),
                    'base_profit' => $base,
                    'transfer_out_equity' => $out,
                    'transfer_in_settlement' => $in,
                    'net_cash_profit' => $netCash,
                    'total_withdrawable_addition' => $netCash + $in,
                    'capital_equity_increase' => $out,
                ];
            }

            $distribution = ProfitDistribution::create([
                'period_month' => $periodMonth,
                'total_profit_pool' => $totalPool,
                'notes' => $request->notes,
                'distribution_breakdown' => [
                    'total_profit_pool' => $totalPool,
                    'period_month' => $periodMonth,
                    'partners' => $breakdownList,
                    'transfers' => $settlementTransfers,
                ],
                'distributed_by' => $user->id,
            ]);

            // Create ledger transactions for each partner
            foreach ($directors as $director) {
                $base = $directorBaseProfits[$director->id] ?? 0.0;
                $out = $transfersOutByDirector[$director->id] ?? 0.0;
                $netCash = max(0, $base - $out);

                // 1. Credit Net Cash Profit Payout (if any remaining)
                if ($netCash > 0) {
                    DirectorTransaction::create([
                        'director_id' => $director->id,
                        'type' => 'profit_credit',
                        'amount' => $netCash,
                        'date' => $request->date,
                        'payment_method' => 'Profit Share Allocation',
                        'reference_no' => "DIV-{$periodMonth}",
                        'period_month' => $periodMonth,
                        'profit_distribution_id' => $distribution->id,
                        'notes' => "Monthly profit share ({$director->share_percentage}%) for {$periodMonth}" . ($out > 0 ? " (Net after Rs. " . number_format($out) . " capital settlement)" : ""),
                        'created_by' => $user->id,
                    ]);
                }

                // 2. Credit Retained Profit to Capital Equity for donor partner
                if ($out > 0) {
                    $recipientNotes = [];
                    foreach ($settlementTransfers as $st) {
                        if ((int)$st['from_director_id'] === $director->id && (float)$st['amount'] > 0) {
                            $toDir = $directors->firstWhere('id', (int)$st['to_director_id']);
                            if ($toDir) {
                                $recipientNotes[] = "Rs. " . number_format((float)$st['amount'], 0) . " to {$toDir->name}";
                            }
                        }
                    }
                    $recipientSummary = !empty($recipientNotes) ? " (" . implode(', ', $recipientNotes) . ")" : "";

                    DirectorTransaction::create([
                        'director_id' => $director->id,
                        'type' => 'capital_injection',
                        'amount' => $out,
                        'date' => $request->date,
                        'payment_method' => 'Retained Profit to Capital Equity',
                        'reference_no' => "EQ-SETTLE-{$periodMonth}",
                        'period_month' => $periodMonth,
                        'profit_distribution_id' => $distribution->id,
                        'notes' => "Capital equity increase from retained profit share{$recipientSummary} for {$periodMonth}",
                        'created_by' => $user->id,
                    ]);
                }
            }

            // 3. Credit Settlement Transfers received by recipient (loan / surplus) partners
            foreach ($settlementTransfers as $st) {
                $fromId = (int) $st['from_director_id'];
                $toId = (int) $st['to_director_id'];
                $amt = (float) $st['amount'];
                $fromDir = $directors->firstWhere('id', $fromId);

                if ($amt > 0 && $fromDir) {
                    DirectorTransaction::create([
                        'director_id' => $toId,
                        'type' => 'profit_credit',
                        'amount' => $amt,
                        'date' => $request->date,
                        'payment_method' => 'Profit Share Transfer',
                        'reference_no' => "REC-DIR-{$fromDir->id}-{$periodMonth}",
                        'period_month' => $periodMonth,
                        'profit_distribution_id' => $distribution->id,
                        'notes' => "Profit Settlement Received (Loan Recovery from {$fromDir->name}) for {$periodMonth}",
                        'created_by' => $user->id,
                    ]);
                }
            }

            DB::commit();

            // Broadcast push notification to all Directors
            FcmService::notifyProfitDistribution($distribution);

            return response()->json([
                'message' => "Successfully distributed Rs. " . number_format($totalPool) . " profit across {$directors->count()} directors for {$periodMonth}.",
                'data' => $distribution,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to distribute profit: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Export Official A4 Director Statement PDF.
     */
    public function exportPdf(Request $request, $id, DocumentPdfService $pdfService)
    {
        $director = Director::findOrFail($id);
        $month = $request->get('month');

        $txQuery = $director->transactions()->with(['creator:id,name,role', 'expense']);

        $openingBalance = 0.0;
        if ($month) {
            $parsedDate = Carbon::parse($month . '-01');
            $startOfMonth = $parsedDate->copy()->startOfMonth()->toDateString();
            $endOfMonth = $parsedDate->copy()->endOfMonth()->toDateString();

            // 1 fast indexed aggregate query for opening balance prior to this month
            $priorAggregates = DB::table('director_transactions')
                ->selectRaw("
                    SUM(CASE WHEN type IN ('capital_injection', 'capital_expense', 'profit_credit') THEN amount ELSE 0 END) as total_credits,
                    SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_debits
                ")
                ->where('director_id', $id)
                ->where('date', '<', $startOfMonth)
                ->first();

            $openingBalance = max(0, (float) ($priorAggregates->total_credits ?? 0) - (float) ($priorAggregates->total_debits ?? 0));

            $txQuery->whereBetween('date', [$startOfMonth, $endOfMonth]);
        }

        $transactions = $txQuery->orderBy('date', 'asc')->orderBy('id', 'asc')->get();

        $runningBalance = $openingBalance;
        $statement = [];

        if ($month && $openingBalance > 0) {
            $prevMonthName = $parsedDate->copy()->subMonth()->format('F Y');
            $statement[] = [
                'type' => 'opening_balance',
                'title' => "Opening Balance (Brought Forward from {$prevMonthName})",
                'reference_no' => 'B/F Balance',
                'date' => $startOfMonth,
                'payment_method' => 'Brought Forward',
                'credit' => $openingBalance,
                'debit' => 0.0,
                'running_balance' => $openingBalance,
                'notes' => 'Carried forward from preceding month',
            ];
        }

        foreach ($transactions as $tx) {
            $isCredit = in_array($tx->type, ['capital_injection', 'capital_expense', 'profit_credit']);
            $amount = (float) $tx->amount;

            if ($isCredit) {
                $runningBalance += $amount;
            } else {
                $runningBalance -= $amount;
            }

            $typeLabel = match ($tx->type) {
                'capital_injection' => 'Direct Capital Deposit',
                'capital_expense' => 'Director Paid Expense (Capital Addition)',
                'profit_credit' => 'Monthly Profit Share',
                'withdrawal' => 'Director Withdrawal',
                default => ucfirst($tx->type),
            };

            $statement[] = [
                'type' => $tx->type,
                'type_label' => $typeLabel,
                'title' => $tx->notes ?: $typeLabel,
                'reference_no' => $tx->reference_no ?? 'Direct Txn',
                'date' => $tx->date->format('Y-m-d'),
                'payment_method' => $tx->payment_method,
                'credit' => $isCredit ? $amount : 0.0,
                'debit' => !$isCredit ? $amount : 0.0,
                'running_balance' => max(0, $runningBalance),
                'notes' => $tx->notes,
                'creator_name' => $tx->creator->name ?? 'Director',
            ];
        }

        $docTitle = $month ? "DIRECTOR EQUITY & CAPITAL STATEMENT ({$month})" : "DIRECTOR EQUITY & CAPITAL STATEMENT";
        $filename = "Director_Statement_{$director->name}_" . ($month ?? date('Y-m')) . ".pdf";

        return $pdfService->download('pdf.director_statement', [
            'lang' => 'en',
            'doc_title' => $docTitle,
            'doc_ref' => 'NSSE-DIR-' . $director->id . '-' . ($month ?? date('Y-m')),
            'doc_date' => date('d-M-Y'),
            'director' => $director,
            'month' => $month,
            'statement' => $statement,
            'opening_balance' => $openingBalance,
            'total_credits' => (float) $transactions->whereIn('type', ['capital_injection', 'capital_expense', 'profit_credit'])->sum('amount'),
            'total_debits' => (float) $transactions->where('type', 'withdrawal')->sum('amount'),
            'closing_balance' => max(0, $runningBalance),
        ], $filename);
    }

    /**
     * Profit & Loss (P & L) Report:
     * Govt Inflow Income - General Expenses = Net Distributable Profit / Surplus
     * Breakdown of partner shares based on configured percentages, monthly expected contributions, and loans/shortfalls.
     */
    public function pnlReport(Request $request)
    {
        $month = $request->get('month'); // e.g. "2026-08" or null for All Time

        // 1. Total Govt Grants / Inflows
        $grantQuery = Grant::query();
        if ($month) {
            $parsedDate = Carbon::parse($month . '-01');
            $start = $parsedDate->copy()->startOfMonth()->toDateString();
            $end = $parsedDate->copy()->endOfMonth()->toDateString();
            $grantQuery->whereBetween('deposit_date', [$start, $end]);
        }
        $totalGovtIncome = (float) $grantQuery->sum('amount');

        // 2. Total General Expenses (Approved / Disbursed)
        $expenseQuery = Expense::where('expense_type', 'general')->whereIn('status', ['approved', 'disbursed']);
        if ($month) {
            $startDateTime = $parsedDate->copy()->startOfMonth()->startOfDay()->toDateTimeString();
            $endDateTime = $parsedDate->copy()->endOfMonth()->endOfDay()->toDateTimeString();
            $expenseQuery->whereBetween('created_at', [$startDateTime, $endDateTime]);
        }
        $totalGeneralExpenses = (float) $expenseQuery->sum('amount');

        // 3. Remaining Distributable Profit / Surplus
        $remainingAmount = max(0, $totalGovtIncome - $totalGeneralExpenses);
        $netMargin = $totalGovtIncome > 0 ? round(($remainingAmount / $totalGovtIncome) * 100, 1) : 0.0;

        // 4. Partner Net Capital Aggregates
        $directors = Director::where('status', 'active')->orderBy('id')->get();
        $dirAggregates = DB::table('director_transactions')
            ->selectRaw("
                director_id,
                SUM(CASE WHEN type IN ('capital_injection', 'capital_expense', 'profit_credit') THEN amount ELSE 0 END) as total_credits,
                SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_debits
            ")
            ->whereIn('type', ['capital_injection', 'profit_credit', 'withdrawal', 'capital_expense'])
            ->groupBy('director_id')
            ->get()
            ->keyBy('director_id');

        $endOfMonth = $month ? Carbon::parse($month . '-01')->endOfMonth()->toDateString() : null;

        // Fetch cumulative capital contributions (injections + expenses) up to this month
        $dirCumulativePayments = DB::table('director_transactions')
            ->selectRaw("
                director_id,
                SUM(CASE WHEN type IN ('capital_injection', 'capital_expense') THEN amount ELSE 0 END) as total_capital_paid
            ")
            ->when($month && $endOfMonth, function ($q) use ($endOfMonth) {
                $q->where('date', '<=', $endOfMonth);
            })
            ->groupBy('director_id')
            ->get()
            ->keyBy('director_id');

        $directorsBreakdown = [];
        $totalExpectedAll = 0.0;
        $totalPaidInMonthAll = 0.0;

        foreach ($directors as $director) {
            $agg = $dirAggregates->get($director->id);
            $credits = (float) ($agg->total_credits ?? 0);
            $debits = (float) ($agg->total_debits ?? 0);
            $netCap = max(0, $credits - $debits);

            $sharePct = (float) $director->share_percentage;
            $expectedAmt = (float) ($director->expected_monthly_contribution ?? 0.0);
            $totalCapitalPaid = (float) ($dirCumulativePayments->get($director->id)?->total_capital_paid ?? 0.0);
            
            $variance = round($totalCapitalPaid - $expectedAmt, 2);
            $isSurplus = $variance > 0;
            $loanAmount = max(0, $variance);
            $shortfallAmount = max(0, -$variance);

            $profitShare = round($remainingAmount * ($sharePct / 100), 2);

            $totalExpectedAll += $expectedAmt;
            $totalPaidInMonthAll += $totalCapitalPaid;

            $directorsBreakdown[] = [
                'id' => $director->id,
                'name' => $director->name,
                'email' => $director->email,
                'phone' => $director->phone,
                'cnic' => $director->cnic,
                'net_capital' => $netCap,
                'live_balance' => $netCap,
                'share_percentage' => $sharePct,
                'expected_monthly_contribution' => $expectedAmt,
                'total_capital_paid' => $totalCapitalPaid,
                'month_paid_amount' => $totalCapitalPaid,
                'contribution_variance' => $variance,
                'is_surplus' => $isSurplus,
                'loan_amount' => $loanAmount,
                'shortfall_amount' => $shortfallAmount,
                'profit_share' => $profitShare,
                'base_profit_share' => $profitShare,
                'bank_name' => $director->bank_name,
                'bank_account_no' => $director->bank_account_no,
            ];
        }

        $distribution = null;
        if ($month) {
            $distRecord = ProfitDistribution::with('distributor:id,name')->where('period_month', $month)->first();
            if ($distRecord) {
                $distribution = [
                    'id' => $distRecord->id,
                    'period_month' => $distRecord->period_month,
                    'total_profit_pool' => (float) $distRecord->total_profit_pool,
                    'distributed_at' => $distRecord->created_at->toIso8601String(),
                    'distributed_by_name' => $distRecord->distributor?->name ?? 'Administrator',
                    'notes' => $distRecord->notes,
                    'breakdown' => $distRecord->distribution_breakdown,
                ];
            }
        }

        return response()->json([
            'month' => $month,
            'summary' => [
                'total_govt_income' => $totalGovtIncome,
                'total_general_expenses' => $totalGeneralExpenses,
                'remaining_amount' => $remainingAmount,
                'remaining_profit' => $remainingAmount,
                'net_margin_percentage' => $netMargin,
                'total_expected_contributions' => $totalExpectedAll,
                'total_paid_in_month' => $totalPaidInMonthAll,
                'is_distributed' => !is_null($distribution),
                'distribution' => $distribution,
            ],
            'directors' => $directorsBreakdown,
        ]);
    }

    /**
     * Dedicated Director Expenses Report / Ledger.
     */
    public function directorExpensesReport(Request $request)
    {
        $query = Expense::with(['director:id,name,email,share_percentage', 'creator:id,name,role'])
            ->where('expense_type', 'director')
            ->orderBy('disbursed_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('director_id')) {
            $query->where('director_id', $request->director_id);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('month')) {
            $month = $request->month; // e.g. "2026-09"
            $start = Carbon::parse($month . '-01')->startOfMonth()->toDateTimeString();
            $end = Carbon::parse($month . '-01')->endOfMonth()->toDateTimeString();
            $query->whereBetween('disbursed_at', [$start, $end]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('payment_reference', 'like', "%{$search}%")
                  ->orWhereHas('director', function ($dq) use ($search) {
                      $dq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $allExpenses = (clone $query)->get();
        $totalAmount = (float) $allExpenses->sum('amount');
        $totalCount = $allExpenses->count();

        // Breakdown by category
        $byCategory = $allExpenses->groupBy('category')->map(function ($items, $cat) use ($totalAmount) {
            $catTotal = (float) $items->sum('amount');
            return [
                'category' => $cat,
                'count' => $items->count(),
                'total_amount' => $catTotal,
                'percentage' => $totalAmount > 0 ? round(($catTotal / $totalAmount) * 100, 1) : 0,
            ];
        })->values();

        // Breakdown by director
        $byDirector = $allExpenses->groupBy('director_id')->map(function ($items, $dId) use ($totalAmount) {
            $dTotal = (float) $items->sum('amount');
            $dirName = $items->first()->director->name ?? 'Partner';
            return [
                'director_id' => $dId,
                'director_name' => $dirName,
                'count' => $items->count(),
                'total_amount' => $dTotal,
                'percentage' => $totalAmount > 0 ? round(($dTotal / $totalAmount) * 100, 1) : 0,
            ];
        })->values();

        $perPage = (int) $request->get('per_page', 50);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'summary' => [
                'total_amount' => $totalAmount,
                'total_count' => $totalCount,
                'breakdown_by_category' => $byCategory,
                'breakdown_by_director' => $byDirector,
            ],
        ]);
    }

    /**
     * Export Director Expenses Report PDF.
     */
    public function exportDirectorExpensesPdf(Request $request, DocumentPdfService $pdfService)
    {
        $query = Expense::with(['director:id,name,email', 'creator:id,name,role'])
            ->where('expense_type', 'director')
            ->orderBy('disbursed_at', 'desc');

        if ($request->filled('director_id')) {
            $query->where('director_id', $request->director_id);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('month')) {
            $month = $request->month;
            $start = Carbon::parse($month . '-01')->startOfMonth()->toDateTimeString();
            $end = Carbon::parse($month . '-01')->endOfMonth()->toDateTimeString();
            $query->whereBetween('disbursed_at', [$start, $end]);
        }

        $expenses = $query->get();
        $totalAmount = (float) $expenses->sum('amount');

        $docTitle = "DIRECTOR EXPENSES & CAPITAL ASSETS REPORT";
        $filename = "Director_Expenses_Report_" . ($request->month ?? date('Y-m')) . ".pdf";

        return $pdfService->download('pdf.director_expenses_report', [
            'lang' => 'en',
            'doc_title' => $docTitle,
            'doc_ref' => 'NSSE-DIR-EXP-' . ($request->month ?? date('Y-m')),
            'doc_date' => date('d-M-Y'),
            'month' => $request->month,
            'expenses' => $expenses,
            'total_amount' => $totalAmount,
        ], $filename);
    }
}
