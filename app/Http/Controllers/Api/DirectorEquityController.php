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
        $totalConsumedAll = 0.0;
        $totalProfitAll = 0.0;
        $totalWithdrawnAll = 0.0;

        foreach ($rawDirectors as $director) {
            $injected = (float) $director->transactions()->where('type', 'capital_injection')->sum('amount');
            $withdrawn = (float) $director->transactions()->where('type', 'withdrawal')->sum('amount');
            $consumed = (float) $director->transactions()->where('type', 'capital_expense')->sum('amount');
            $profit = (float) $director->transactions()->where('type', 'profit_credit')->sum('amount');
            $netCapital = max(0, ($injected + $profit) - ($consumed + $withdrawn));
            $liveBalance = $netCapital;

            $totalNetCapitalAll += $netCapital;
            $totalCapitalInjectedAll += $injected;
            $totalConsumedAll += $consumed;
            $totalProfitAll += $profit;
            $totalWithdrawnAll += $withdrawn;

            $directorsData[] = [
                'id' => $director->id,
                'user_id' => $director->user_id,
                'name' => $director->name,
                'cnic' => $director->cnic,
                'phone' => $director->phone,
                'email' => $director->email,
                'bank_name' => $director->bank_name,
                'bank_account_no' => $director->bank_account_no,
                'bank_iban' => $director->bank_iban,
                'status' => $director->status,
                'net_capital' => $netCapital,
                'total_capital_injected' => $injected,
                'total_capital_consumed' => $consumed,
                'total_profit_earned' => $profit,
                'total_withdrawn' => $withdrawn,
                'live_balance' => $liveBalance,
            ];
        }

        // Calculate dynamic capital share percentage based on Net Capital (Injected - Withdrawn)
        $directors = array_map(function ($d) use ($totalNetCapitalAll) {
            $dynamicShare = $totalNetCapitalAll > 0 
                ? round(($d['net_capital'] / $totalNetCapitalAll) * 100, 2) 
                : 0.00;
            $d['share_percentage'] = $dynamicShare;
            return $d;
        }, $directorsData);

        $totalSharePercentage = (float) array_sum(array_column($directors, 'share_percentage'));
        $totalUndrawnPool = max(0, ($totalCapitalInjectedAll + $totalProfitAll) - ($totalConsumedAll + $totalWithdrawnAll));

        // Recent Batches
        $recentDistributions = ProfitDistribution::with('distributor:id,name')->latest()->take(6)->get();

        return response()->json([
            'data' => $directors,
            'summary' => [
                'total_directors' => count($directors),
                'total_net_capital' => $totalNetCapitalAll,
                'total_capital_injected' => $totalCapitalInjectedAll,
                'total_capital_consumed' => $totalConsumedAll,
                'total_profit_allocated' => $totalProfitAll,
                'total_withdrawn' => $totalWithdrawnAll,
                'total_undrawn_pool' => $totalUndrawnPool,
                'total_share_percentage' => $totalSharePercentage,
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
            'cnic' => 'nullable|string|max:25',
            'phone' => 'nullable|string|max:25',
            'email' => 'nullable|email|max:255',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_no' => 'nullable|string|max:50',
            'bank_iban' => 'nullable|string|max:50',
        ]);

        $director = Director::create([
            'name' => $request->name,
            'share_percentage' => $request->share_percentage,
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
            'cnic' => 'nullable|string|max:25',
            'phone' => 'nullable|string|max:25',
            'email' => 'nullable|email|max:255',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_no' => 'nullable|string|max:50',
            'bank_iban' => 'nullable|string|max:50',
            'status' => 'nullable|in:active,inactive',
        ]);

        $director->update($request->only([
            'name', 'share_percentage', 'cnic', 'phone', 'email',
            'bank_name', 'bank_account_no', 'bank_iban', 'status'
        ]));

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

        // Single indexed aggregate query for this partner's net capital (injected - withdrawn)
        $lifetimeAggregates = DB::table('director_transactions')
            ->selectRaw("
                SUM(CASE WHEN type = 'capital_injection' THEN amount ELSE 0 END) as total_injected,
                SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_withdrawn,
                SUM(CASE WHEN type = 'capital_expense' THEN amount ELSE 0 END) as total_consumed,
                SUM(CASE WHEN type = 'profit_credit' THEN amount ELSE 0 END) as total_profit
            ")
            ->where('director_id', $id)
            ->first();

        $lifetimeInjected = (float) ($lifetimeAggregates->total_injected ?? 0);
        $lifetimeWithdrawn = (float) ($lifetimeAggregates->total_withdrawn ?? 0);
        $lifetimeConsumed = (float) ($lifetimeAggregates->total_consumed ?? 0);
        $lifetimeProfit = (float) ($lifetimeAggregates->total_profit ?? 0);
        $lifetimeNetCapital = max(0, $lifetimeInjected - $lifetimeWithdrawn);
        $lifetimeLiveBalance = max(0, ($lifetimeInjected + $lifetimeProfit) - ($lifetimeConsumed + $lifetimeWithdrawn));

        $txQuery = $director->transactions()->with(['creator:id,name,role', 'expense']);

        $openingBalance = 0.0;
        if ($month) {
            $parsedDate = Carbon::parse($month . '-01');
            $startOfMonth = $parsedDate->copy()->startOfMonth()->toDateString();
            $endOfMonth = $parsedDate->copy()->endOfMonth()->toDateString();

            // 1 fast indexed aggregate query for opening balance prior to this month
            $priorAggregates = DB::table('director_transactions')
                ->selectRaw("
                    SUM(CASE WHEN type IN ('capital_injection', 'profit_credit') THEN amount ELSE 0 END) as total_credits,
                    SUM(CASE WHEN type IN ('withdrawal', 'capital_expense') THEN amount ELSE 0 END) as total_debits
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
            $isCredit = in_array($tx->type, ['capital_injection', 'profit_credit']);
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
                'capital_injection' => 'Capital Deposit',
                'capital_expense' => 'Capital Usage / Expense',
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

        $periodCredits = $periodInjections + $periodProfitShares;
        $periodDebits = $periodUsage + $periodDrawings;
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
                'total_capital_consumed' => $lifetimeConsumed,
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

        if (!in_array($user->role, ['chairman', 'super_admin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized. Only the Chairman can deposit capital or record withdrawals.'], 403);
        }

        $request->validate([
            'director_id' => 'required|exists:directors,id',
            'type' => 'required|in:capital_injection,withdrawal',
            'amount' => 'required|numeric|min:1|max:999999999',
            'date' => 'required|date',
            'payment_method' => 'required|string|max:100',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'slip' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        $director = Director::findOrFail($request->director_id);

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
                ? "Capital injection of Rs. " . number_format($request->amount) . " credited to {$director->name}."
                : "Withdrawal of Rs. " . number_format($request->amount) . " debited from {$director->name}.",
            'data' => $transaction,
        ], 201);
    }

    /**
     * Record a Capital Expense consumed from a specific Director's Capital Balance.
     */
    public function storeCapitalExpense(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['chairman', 'super_admin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized. Only the Chairman can record capital expenses.'], 403);
        }

        $request->validate([
            'director_id' => 'required|exists:directors,id',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1|max:999999999',
            'date' => 'required|date',
            'category_id' => 'nullable|exists:categories,id',
            'payment_method' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        DB::beginTransaction();
        try {
            // Pessimistic lock on Director record to prevent overdraw concurrency
            $director = Director::where('id', $request->director_id)->lockForUpdate()->firstOrFail();
            $liveBalance = $director->live_balance;

            if ($request->amount > $liveBalance) {
                if ($receiptPath && Storage::disk('public')->exists($receiptPath)) {
                    Storage::disk('public')->delete($receiptPath);
                }
                return response()->json([
                    'message' => "Insufficient capital balance for {$director->name}. Available: Rs. " . number_format($liveBalance, 0) . ", requested: Rs. " . number_format($request->amount, 0) . ".",
                ], 422);
            }

            // Resolve Category Name
            $categoryName = 'Capital Expenditure';
            if ($request->filled('category_id')) {
                $cat = \App\Models\Category::find($request->category_id);
                if ($cat) {
                    $categoryName = $cat->name;
                } elseif (is_string($request->category_id) && !is_numeric($request->category_id)) {
                    $categoryName = $request->category_id;
                }
            } elseif ($request->filled('category')) {
                $categoryName = $request->category;
            }

            $tierInfo = Expense::resolveTier((float) $request->amount);
            $voucherNo = 'CAP-' . strtoupper(uniqid());

            // Create Expense record
            $expense = Expense::create([
                'voucher_no' => $voucherNo,
                'title' => $request->title,
                'category' => $categoryName,
                'description' => $request->notes ?: $request->title,
                'amount' => $request->amount,
                'amount_tier' => $tierInfo['tier'],
                'tier_color' => $tierInfo['color'],
                'expense_type' => 'capital',
                'director_id' => $director->id,
                'payment_method' => $request->payment_method ?? 'Capital Account',
                'payment_reference' => $voucherNo,
                'receipt_url' => $receiptPath,
                'status' => 'disbursed',
                'created_by' => $user->id,
                'disbursed_by' => $user->id,
                'disbursed_at' => $request->date ?? now(),
            ]);

            // Create DirectorTransaction debit record
            $transaction = DirectorTransaction::create([
                'director_id' => $director->id,
                'type' => 'capital_expense',
                'amount' => $request->amount,
                'date' => $request->date,
                'payment_method' => $request->payment_method ?? 'Capital Account',
                'reference_no' => $voucherNo,
                'expense_id' => $expense->id,
                'slip_url' => $receiptPath,
                'notes' => $request->title . ($request->notes ? " - {$request->notes}" : ''),
                'created_by' => $user->id,
            ]);

            DB::commit();

            // Broadcast push notification to all Directors
            FcmService::notifyDirectorTransaction($transaction);

            return response()->json([
                'message' => "Capital expense of Rs. " . number_format($request->amount) . " successfully recorded against {$director->name}.",
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
     * Execute Month-End Profit Distribution Batch (1-Click Formula Split).
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

        $directors = Director::where('status', 'active')->get();
        if ($directors->isEmpty()) {
            return response()->json(['message' => 'No active directors found.'], 422);
        }

        // Calculate total net capital (Injected + Profit Credits - Consumed - Withdrawn)
        $directorsNetCapital = [];
        $totalNetCapitalAll = 0.0;
        foreach ($directors as $director) {
            $credits = (float) $director->transactions()->whereIn('type', ['capital_injection', 'profit_credit'])->sum('amount');
            $debits = (float) $director->transactions()->whereIn('type', ['withdrawal', 'capital_expense'])->sum('amount');
            $netCap = max(0, $credits - $debits);
            $directorsNetCapital[$director->id] = $netCap;
            $totalNetCapitalAll += $netCap;
        }

        if ($totalNetCapitalAll <= 0) {
            return response()->json(['message' => 'Active net capital must be available to distribute profits.'], 422);
        }

        DB::beginTransaction();
        try {
            $distribution = ProfitDistribution::create([
                'period_month' => $periodMonth,
                'total_profit_pool' => $totalPool,
                'notes' => $request->notes,
                'distributed_by' => $user->id,
            ]);

            foreach ($directors as $director) {
                $netCap = $directorsNetCapital[$director->id] ?? 0.0;
                $directorSharePct = round(($netCap / $totalNetCapitalAll) * 100, 2);
                $directorShare = round($totalPool * ($netCap / $totalNetCapitalAll), 2);

                if ($directorShare > 0) {
                    DirectorTransaction::create([
                        'director_id' => $director->id,
                        'type' => 'profit_credit',
                        'amount' => $directorShare,
                        'date' => $request->date,
                        'payment_method' => 'Profit Share Allocation',
                        'reference_no' => "DIV-{$periodMonth}",
                        'period_month' => $periodMonth,
                        'profit_distribution_id' => $distribution->id,
                        'notes' => "Monthly profit share ({$directorSharePct}% net capital share) for {$periodMonth}",
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
                    SUM(CASE WHEN type IN ('capital_injection', 'profit_credit') THEN amount ELSE 0 END) as total_credits,
                    SUM(CASE WHEN type IN ('withdrawal', 'capital_expense') THEN amount ELSE 0 END) as total_debits
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
            $isCredit = in_array($tx->type, ['capital_injection', 'profit_credit']);
            $amount = (float) $tx->amount;

            if ($isCredit) {
                $runningBalance += $amount;
            } else {
                $runningBalance -= $amount;
            }

            $typeLabel = match ($tx->type) {
                'capital_injection' => 'Capital Deposit',
                'capital_expense' => 'Capital Usage / Expense',
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
            'total_credits' => (float) $transactions->whereIn('type', ['capital_injection', 'profit_credit'])->sum('amount'),
            'total_debits' => (float) $transactions->whereIn('type', ['withdrawal', 'capital_expense'])->sum('amount'),
            'closing_balance' => max(0, $runningBalance),
        ], $filename);
    }

    /**
     * Profit & Loss (P & L) Report:
     * Govt Inflow Income - General Expenses = Net Distributable Profit / Surplus
     * Breakdown of partner shares.
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

        // 3. Remaining Surplus / Profit
        $remainingAmount = max(0, $totalGovtIncome - $totalGeneralExpenses);
        $netMargin = $totalGovtIncome > 0 ? round(($remainingAmount / $totalGovtIncome) * 100, 1) : 0.0;

        // 4. Partner Dynamic Shares & Profit Allocation (1 fast grouped indexed query)
        $directors = Director::where('status', 'active')->orderBy('id')->get();
        $dirAggregates = DB::table('director_transactions')
            ->selectRaw("
                director_id,
                SUM(CASE WHEN type IN ('capital_injection', 'profit_credit') THEN amount ELSE 0 END) as total_credits,
                SUM(CASE WHEN type IN ('withdrawal', 'capital_expense') THEN amount ELSE 0 END) as total_debits
            ")
            ->whereIn('type', ['capital_injection', 'profit_credit', 'withdrawal', 'capital_expense'])
            ->groupBy('director_id')
            ->get()
            ->keyBy('director_id');

        $directorsNetCapital = [];
        $totalNetCapitalAll = 0.0;

        foreach ($directors as $director) {
            $agg = $dirAggregates->get($director->id);
            $credits = (float) ($agg->total_credits ?? 0);
            $debits = (float) ($agg->total_debits ?? 0);
            $netCap = max(0, $credits - $debits);
            $directorsNetCapital[$director->id] = $netCap;
            $totalNetCapitalAll += $netCap;
        }

        $directorsBreakdown = [];
        foreach ($directors as $director) {
            $netCap = $directorsNetCapital[$director->id] ?? 0.0;
            $sharePercentage = $totalNetCapitalAll > 0 
                ? round(($netCap / $totalNetCapitalAll) * 100, 2) 
                : (float) $director->share_percentage;
            
            $profitShare = round($remainingAmount * ($sharePercentage / 100), 2);

            $directorsBreakdown[] = [
                'id' => $director->id,
                'name' => $director->name,
                'email' => $director->email,
                'phone' => $director->phone,
                'cnic' => $director->cnic,
                'net_capital' => $netCap,
                'share_percentage' => $sharePercentage,
                'profit_share' => $profitShare,
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
                'is_distributed' => !is_null($distribution),
                'distribution' => $distribution,
            ],
            'directors' => $directorsBreakdown,
        ]);
    }
}
