<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\Cheque;
use App\Models\Expense;
use App\Models\CashInflow;
use App\Services\DocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class BankStatementController extends Controller
{
    /**
     * Live Financial Summary Scoped to Selected Month (Government & Operational Funds).
     */
    public function financialSummary(Request $request)
    {
        $selectedMonth = $request->get('month', now()->format('Y-m')); // e.g. "2026-08"
        
        try {
            $parsedDate = Carbon::parse($selectedMonth . '-01');
        } catch (\Throwable $e) {
            $parsedDate = now();
            $selectedMonth = now()->format('Y-m');
        }

        $startOfMonth = $parsedDate->copy()->startOfMonth()->toDateString();
        $endOfMonth = $parsedDate->copy()->endOfMonth()->toDateString();
        $startOfMonthTime = $parsedDate->copy()->startOfMonth()->toDateTimeString();
        $endOfMonthTime = $parsedDate->copy()->endOfMonth()->toDateTimeString();

        // 1. Month-Specific Bank Inflow (Grants deposited this month)
        $monthGrants = (float) Grant::whereBetween('deposit_date', [$startOfMonth, $endOfMonth])->sum('amount');

        // 2. Month-Specific Bank Outflow (Cheques cashed at bank this month)
        $monthCashedCheques = (float) Cheque::where('status', 'cashed')
            ->whereBetween('cashed_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // 3. Month-Specific Petty Cash Drawer Inflow (Self-cash cheques cashed this month + direct cash inflows)
        $monthPettyCashCheques = (float) Cheque::where('status', 'cashed')
            ->where('purpose', 'petty_cash')
            ->whereBetween('cashed_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');
        
        $monthDirectCashInflow = (float) CashInflow::whereBetween('deposit_date', [$startOfMonth, $endOfMonth])->sum('amount');
        $monthPettyCashInflow = $monthPettyCashCheques + $monthDirectCashInflow;

        // 4. Month-Specific General Operational Expenses (Excluding partner capital expenses)
        $monthExpenses = (float) Expense::where('expense_type', 'general')
            ->whereBetween('created_at', [$startOfMonthTime, $endOfMonthTime])
            ->sum('amount');

        // 5. Month-Specific Cash Expenses
        $monthCashExpenses = (float) Expense::where('expense_type', 'general')
            ->where('payment_method', 'Cash')
            ->whereBetween('created_at', [$startOfMonthTime, $endOfMonthTime])
            ->sum('amount');

        // 6. Cumulative / Running Overall Institutional Balances
        $totalGrantsAllTime = (float) Grant::sum('amount');
        $totalCashedChequesAllTime = (float) Cheque::where('status', 'cashed')->sum('amount');
        $totalPendingChequesAllTime = (float) Cheque::where('status', 'issued')->sum('amount');
        $bankBalance = max(0, $totalGrantsAllTime - $totalCashedChequesAllTime);

        $totalPettyCashChequesAllTime = (float) Cheque::where('status', 'cashed')
            ->where('purpose', 'petty_cash')
            ->sum('amount');
        $totalDirectCashInflowAllTime = (float) CashInflow::sum('amount');
        $totalCashExpensesAllTime = (float) Expense::where('expense_type', 'general')
            ->where('payment_method', 'Cash')
            ->sum('amount');
        $cashInHand = max(0, ($totalPettyCashChequesAllTime + $totalDirectCashInflowAllTime) - $totalCashExpensesAllTime);

        $totalExpensesAllTime = (float) Expense::where('expense_type', 'general')->sum('amount');

        return response()->json([
            'selected_month' => $selectedMonth,
            'month_name' => $parsedDate->format('F Y'),
            
            // Month-Scoped Values
            'month_grants' => $monthGrants,
            'month_cheques_cashed' => $monthCashedCheques,
            'month_petty_cash_inflow' => $monthPettyCashInflow,
            'month_expenses' => $monthExpenses,
            'month_cash_expenses' => $monthCashExpenses,
            'month_bank_net' => $monthGrants - $monthCashedCheques,
            'month_cash_net' => $monthPettyCashInflow - $monthCashExpenses,

            // All-Time / Running Balances
            'bank_balance' => $bankBalance,
            'cash_in_hand' => $cashInHand,
            'total_grants_received' => $totalGrantsAllTime,
            'total_cheques_cashed' => $totalCashedChequesAllTime,
            'pending_cheques_amount' => $totalPendingChequesAllTime,
            'total_expenses' => $totalExpensesAllTime,
        ]);
    }

    /**
     * Chronological Passbook / Bank Statement Combining Grants (Credit) & Cashed Cheques (Debit).
     */
    public function statement(Request $request)
    {
        $month = $request->get('month'); // optional filter e.g. "2026-08"

        $grantsQuery = Grant::query();
        $chequesQuery = Cheque::where('status', 'cashed');

        $openingBalance = 0.0;
        if ($month) {
            $parsedDate = Carbon::parse($month . '-01');
            $startOfMonth = $parsedDate->copy()->startOfMonth()->toDateString();
            $endOfMonth = $parsedDate->copy()->endOfMonth()->toDateString();

            // Opening bank balance prior to this month
            $priorGrants = (float) Grant::where('deposit_date', '<', $startOfMonth)->sum('amount');
            $priorCashedCheques = (float) Cheque::where('status', 'cashed')
                ->where('cashed_date', '<', $startOfMonth)
                ->sum('amount');
            $openingBalance = max(0, $priorGrants - $priorCashedCheques);

            $grantsQuery->whereBetween('deposit_date', [$startOfMonth, $endOfMonth]);
            $chequesQuery->whereBetween('cashed_date', [$startOfMonth, $endOfMonth]);
        }

        // Fetch grants (Credit)
        $grants = $grantsQuery->get()->map(function ($g) {
            return [
                'id' => 'grant_' . $g->id,
                'type' => 'credit',
                'category' => 'Govt Grant',
                'title' => $g->title,
                'reference' => $g->reference_no ?? 'Tranche Deposit',
                'date' => $g->deposit_date->format('Y-m-d'),
                'raw_date' => $g->deposit_date->format('Y-m-d H:i:s'),
                'credit' => (float) $g->amount,
                'debit' => 0.0,
                'slip_url' => $g->slip_url,
                'notes' => $g->notes,
                'amount_tier' => 4,
                'tier_color' => 'green',
            ];
        });

        // Fetch cashed cheques (Debit)
        $cheques = $chequesQuery->get()->map(function ($c) {
            $isPettyCash = ($c->purpose === 'petty_cash');
            return [
                'id' => 'cheque_' . $c->id,
                'type' => 'debit',
                'category' => $isPettyCash ? 'Drawer Replenishment' : ucfirst(str_replace('_', ' ', $c->purpose)),
                'title' => "Cheque #{$c->cheque_no} - {$c->beneficiary}",
                'reference' => $c->cheque_no,
                'date' => $c->cashed_date ? $c->cashed_date->format('Y-m-d') : $c->issue_date->format('Y-m-d'),
                'raw_date' => ($c->cashed_date ?? $c->issue_date)->format('Y-m-d H:i:s'),
                'credit' => 0.0,
                'debit' => (float) $c->amount,
                'slip_url' => $c->slip_url,
                'notes' => $c->description,
                'amount_tier' => $c->amount_tier,
                'tier_color' => $c->tier_color,
                'is_petty_cash' => $isPettyCash,
            ];
        });

        $merged = $grants->concat($cheques)->sortBy('raw_date')->values();

        $runningBalance = $openingBalance;
        $statement = [];

        // Prepend Brought Forward Opening Balance if viewing a month with prior history
        if ($month && $openingBalance > 0) {
            $prevMonthName = $parsedDate->copy()->subMonth()->format('F Y');
            $statement[] = [
                'id' => 'opening_balance_bank',
                'type' => 'credit',
                'category' => 'Opening Balance',
                'title' => "Opening Balance (Brought Forward from {$prevMonthName})",
                'reference' => 'B/F Balance',
                'date' => $startOfMonth,
                'raw_date' => $startOfMonth . ' 00:00:00',
                'credit' => $openingBalance,
                'debit' => 0.0,
                'running_balance' => $openingBalance,
                'notes' => 'Carried forward from preceding month',
            ];
        }

        foreach ($merged as $item) {
            if ($item['type'] === 'credit') {
                $runningBalance += $item['credit'];
            } else {
                $runningBalance -= $item['debit'];
            }

            $item['running_balance'] = max(0, $runningBalance);
            $statement[] = $item;
        }

        $statement = array_reverse($statement);

        return response()->json([
            'data' => $statement,
            'opening_balance' => $openingBalance,
            'current_bank_balance' => max(0, $runningBalance),
            'total_credits' => (float) $grants->sum('credit'),
            'total_debits' => (float) $cheques->sum('debit'),
        ]);
    }

    /**
     * Export Chronological Bank Passbook as A4 PDF.
     */
    public function exportPdf(Request $request, DocumentPdfService $pdfService)
    {
        $month = $request->get('month'); // optional filter e.g. "2026-08"

        $grantsQuery = Grant::query();
        $chequesQuery = Cheque::where('status', 'cashed');

        $openingBalance = 0.0;
        if ($month) {
            $parsedDate = Carbon::parse($month . '-01');
            $startOfMonth = $parsedDate->copy()->startOfMonth()->toDateString();
            $endOfMonth = $parsedDate->copy()->endOfMonth()->toDateString();

            // Opening bank balance prior to this month
            $priorGrants = (float) Grant::where('deposit_date', '<', $startOfMonth)->sum('amount');
            $priorCashedCheques = (float) Cheque::where('status', 'cashed')
                ->where('cashed_date', '<', $startOfMonth)
                ->sum('amount');
            $openingBalance = max(0, $priorGrants - $priorCashedCheques);

            $grantsQuery->whereBetween('deposit_date', [$startOfMonth, $endOfMonth]);
            $chequesQuery->whereBetween('cashed_date', [$startOfMonth, $endOfMonth]);
        }

        // Fetch grants (Credit)
        $grants = $grantsQuery->get()->map(function ($g) {
            return [
                'id' => 'grant_' . $g->id,
                'type' => 'credit',
                'category' => 'Govt Grant',
                'title' => $g->title,
                'reference' => $g->reference_no ?? 'Tranche Deposit',
                'date' => $g->deposit_date ? $g->deposit_date->format('Y-m-d') : null,
                'raw_date' => $g->deposit_date ? $g->deposit_date->format('Y-m-d H:i:s') : '',
                'credit' => (float) $g->amount,
                'debit' => 0.0,
                'notes' => $g->notes,
            ];
        });

        // Fetch cashed cheques (Debit)
        $cheques = $chequesQuery->get()->map(function ($c) {
            $isPettyCash = ($c->purpose === 'petty_cash');
            return [
                'id' => 'cheque_' . $c->id,
                'type' => 'debit',
                'category' => $isPettyCash ? 'Drawer Replenishment' : ucfirst(str_replace('_', ' ', $c->purpose)),
                'title' => "Cheque #{$c->cheque_no} - {$c->beneficiary}",
                'reference' => $c->cheque_no,
                'date' => $c->cashed_date ? $c->cashed_date->format('Y-m-d') : ($c->issue_date ? $c->issue_date->format('Y-m-d') : null),
                'raw_date' => ($c->cashed_date ?? $c->issue_date)?->format('Y-m-d H:i:s') ?? '',
                'credit' => 0.0,
                'debit' => (float) $c->amount,
                'notes' => $c->description,
            ];
        });

        $merged = $grants->concat($cheques)->sortBy('raw_date')->values();

        $runningBalance = $openingBalance;
        $statement = [];

        if ($month && $openingBalance > 0) {
            $prevMonthName = $parsedDate->copy()->subMonth()->format('F Y');
            $statement[] = [
                'id' => 'opening_balance_bank',
                'type' => 'credit',
                'category' => 'Opening Balance',
                'title' => "Opening Balance (Brought Forward from {$prevMonthName})",
                'reference' => 'B/F Balance',
                'date' => $startOfMonth,
                'raw_date' => $startOfMonth . ' 00:00:00',
                'credit' => $openingBalance,
                'debit' => 0.0,
                'running_balance' => $openingBalance,
                'notes' => 'Carried forward from preceding month',
            ];
        }

        foreach ($merged as $item) {
            if ($item['type'] === 'credit') {
                $runningBalance += $item['credit'];
            } else {
                $runningBalance -= $item['debit'];
            }

            $item['running_balance'] = max(0, $runningBalance);
            $statement[] = $item;
        }

        $totalCredits = (float) $grants->sum('credit');
        $totalDebits = (float) $cheques->sum('debit');
        $closingBalance = max(0, $runningBalance);

        $docTitle = $month ? "OFFICIAL DIGITAL BANK PASSBOOK STATEMENT ({$month})" : "OFFICIAL DIGITAL BANK PASSBOOK STATEMENT";
        $filename = "Bank_Passbook_" . ($month ?? date('Y-m')) . ".pdf";

        return $pdfService->download('pdf.passbook_report', [
            'lang' => 'en',
            'doc_title' => $docTitle,
            'doc_ref' => 'NSSE-BANK-' . ($month ?? date('Y-m')),
            'doc_date' => date('d-M-Y'),
            'month' => $month,
            'statement' => $statement,
            'opening_balance' => $openingBalance,
            'total_credits' => $totalCredits,
            'total_debits' => $totalDebits,
            'closing_balance' => $closingBalance,
        ], $filename);
    }

    /**
     * Itemized Cash Drawer Ledger Statement (Credits: Cashed Petty Cheques + Inflows, Debits: Cash Expenses).
     */
    public function cashDrawerStatement(Request $request)
    {
        $month = $request->get('month'); // optional filter e.g. "2026-08"

        $chequesQuery = Cheque::where('status', 'cashed')->where('purpose', 'petty_cash');
        $inflowsQuery = CashInflow::query();
        $expensesQuery = Expense::where('payment_method', 'Cash');

        $openingDrawerBalance = 0.0;
        if ($month) {
            $parsedDate = Carbon::parse($month . '-01');
            $startOfMonth = $parsedDate->copy()->startOfMonth()->toDateString();
            $endOfMonth = $parsedDate->copy()->endOfMonth()->toDateString();
            $startOfMonthTime = $parsedDate->copy()->startOfMonth()->toDateTimeString();
            $endOfMonthTime = $parsedDate->copy()->endOfMonth()->toDateTimeString();

            // Opening Cash in Drawer before this month
            $priorPettyCheques = (float) Cheque::where('status', 'cashed')
                ->where('purpose', 'petty_cash')
                ->where('cashed_date', '<', $startOfMonth)
                ->sum('amount');
            $priorCashInflows = (float) CashInflow::where('deposit_date', '<', $startOfMonth)->sum('amount');
            $priorCashExpenses = (float) Expense::where('payment_method', 'Cash')
                ->where('created_at', '<', $startOfMonthTime)
                ->sum('amount');
            $openingDrawerBalance = max(0, ($priorPettyCheques + $priorCashInflows) - $priorCashExpenses);

            $chequesQuery->whereBetween('cashed_date', [$startOfMonth, $endOfMonth]);
            $inflowsQuery->whereBetween('deposit_date', [$startOfMonth, $endOfMonth]);
            $expensesQuery->whereBetween('created_at', [$startOfMonthTime, $endOfMonthTime]);
        }

        // 1. Cheques Cashed from Bank into Drawer (Credit / Inflow)
        $drawerCheques = $chequesQuery->get()->map(function ($c) {
            return [
                'id' => 'drawer_chq_' . $c->id,
                'type' => 'credit',
                'source' => 'Bank Withdrawal (Cheque)',
                'title' => "Cash Drawn from Bank (Cheque #{$c->cheque_no})",
                'reference' => $c->cheque_no,
                'date' => $c->cashed_date ? $c->cashed_date->format('Y-m-d') : ($c->issue_date ? $c->issue_date->format('Y-m-d') : null),
                'raw_date' => ($c->cashed_date ?? $c->issue_date)?->format('Y-m-d H:i:s') ?? '',
                'inflow' => (float) $c->amount,
                'outflow' => 0.0,
                'slip_url' => $c->slip_url,
                'notes' => $c->description,
            ];
        });

        // 2. Direct Cash Inflows into Drawer (Credit / Inflow)
        $directInflows = $inflowsQuery->get()->map(function ($inflow) {
            return [
                'id' => 'direct_inflow_' . $inflow->id,
                'type' => 'credit',
                'source' => $inflow->category,
                'title' => $inflow->title,
                'reference' => $inflow->reference_no ?? 'Cash Receipt',
                'date' => $inflow->deposit_date ? $inflow->deposit_date->format('Y-m-d') : null,
                'raw_date' => $inflow->deposit_date ? $inflow->deposit_date->format('Y-m-d H:i:s') : '',
                'inflow' => (float) $inflow->amount,
                'outflow' => 0.0,
                'slip_url' => $inflow->slip_url,
                'notes' => $inflow->notes,
            ];
        });

        // 3. Cash Expenses Paid out from Drawer (Debit / Outflow)
        $cashExpenses = $expensesQuery->get()->map(function ($exp) {
            return [
                'id' => 'cash_exp_' . $exp->id,
                'type' => 'debit',
                'source' => $exp->category,
                'title' => $exp->title,
                'reference' => 'Cash Voucher',
                'date' => $exp->created_at->format('Y-m-d'),
                'raw_date' => $exp->created_at->format('Y-m-d H:i:s'),
                'inflow' => 0.0,
                'outflow' => (float) $exp->amount,
                'slip_url' => $exp->receipt_url,
                'notes' => $exp->description,
            ];
        });

        $merged = $drawerCheques->concat($directInflows)->concat($cashExpenses)->sortBy('raw_date')->values();

        $runningBalance = $openingDrawerBalance;
        $statement = [];

        // Prepend Brought Forward Opening Cash Drawer Balance if viewing a month with prior history
        if ($month && $openingDrawerBalance > 0) {
            $prevMonthName = $parsedDate->copy()->subMonth()->format('F Y');
            $statement[] = [
                'id' => 'opening_balance_drawer',
                'type' => 'credit',
                'source' => 'Opening Balance',
                'title' => "Opening Cash on Hand (Brought Forward from {$prevMonthName})",
                'reference' => 'B/F Balance',
                'date' => $startOfMonth,
                'raw_date' => $startOfMonth . ' 00:00:00',
                'inflow' => $openingDrawerBalance,
                'outflow' => 0.0,
                'running_balance' => $openingDrawerBalance,
                'notes' => 'Carried forward from preceding month',
            ];
        }

        foreach ($merged as $item) {
            if ($item['type'] === 'credit') {
                $runningBalance += $item['inflow'];
            } else {
                $runningBalance -= $item['outflow'];
            }

            $item['running_balance'] = max(0, $runningBalance);
            $statement[] = $item;
        }

        $statement = array_reverse($statement);

        return response()->json([
            'data' => $statement,
            'opening_balance' => $openingDrawerBalance,
            'current_cash_in_hand' => max(0, $runningBalance),
            'total_inflows' => (float) $drawerCheques->sum('inflow') + (float) $directInflows->sum('inflow'),
            'total_outflows' => (float) $cashExpenses->sum('outflow'),
        ]);
    }

    /**
     * Record a Direct Incoming Cash Transaction to the Cash Drawer.
     */
    public function storeCashInflow(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1|max:999999999',
            'deposit_date' => 'required|date',
            'category' => 'required|string|max:100',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'slip' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        $slipUrl = null;
        if ($request->hasFile('slip')) {
            $path = $request->file('slip')->store('cash_inflows', 'public');
            $slipUrl = Storage::url($path);
        }

        $inflow = CashInflow::create([
            'title' => $request->title,
            'amount' => $request->amount,
            'deposit_date' => $request->deposit_date,
            'category' => $request->category,
            'reference_no' => $request->reference_no,
            'slip_url' => $slipUrl,
            'notes' => $request->notes,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Cash Drawer Inflow recorded successfully.',
            'data' => $inflow,
        ], 201);
    }

    /**
     * List all direct cash inflows.
     */
    public function getCashInflows(Request $request)
    {
        $inflows = CashInflow::with('creator')->latest('deposit_date')->get();
        return response()->json(['data' => $inflows]);
    }
}
