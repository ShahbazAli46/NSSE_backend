<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\FcmService;
use App\Services\DocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    /**
     * List expenses with filters and tier metadata.
     */
    public function index(Request $request)
    {
        $query = Expense::with([
            'creator:id,name,role',
            'director:id,name,email',
            'cheque:id,cheque_no,beneficiary,amount',
        ])->orderBy('created_at', 'desc');

        if ($request->filled('expense_type') && $request->expense_type !== 'all') {
            $query->where('expense_type', $request->expense_type);
        }

        if ($request->filled('tier')) {
            $query->where('amount_tier', (int) $request->tier);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('date')) {
            $date = $request->date;
            $query->whereBetween('created_at', [$date . ' 00:00:00', $date . ' 23:59:59']);
        } elseif ($request->filled('month')) {
            $month = $request->month; // e.g. "2026-08"
            $start = \Illuminate\Support\Carbon::parse($month . '-01')->startOfMonth()->toDateTimeString();
            $end = \Illuminate\Support\Carbon::parse($month . '-01')->endOfMonth()->toDateTimeString();
            $query->whereBetween('created_at', [$start, $end]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tierSummary = (clone $query)->selectRaw("
            COUNT(*) as total_count,
            COALESCE(SUM(amount), 0) as total_amount,
            COUNT(CASE WHEN amount_tier = 1 THEN 1 END) as tier_1_count,
            COALESCE(SUM(CASE WHEN amount_tier = 1 THEN amount ELSE 0 END), 0) as tier_1_amount,
            COUNT(CASE WHEN amount_tier = 2 THEN 1 END) as tier_2_count,
            COALESCE(SUM(CASE WHEN amount_tier = 2 THEN amount ELSE 0 END), 0) as tier_2_amount,
            COUNT(CASE WHEN amount_tier = 3 THEN 1 END) as tier_3_count,
            COALESCE(SUM(CASE WHEN amount_tier = 3 THEN amount ELSE 0 END), 0) as tier_3_amount,
            COUNT(CASE WHEN amount_tier = 4 THEN 1 END) as tier_4_count,
            COALESCE(SUM(CASE WHEN amount_tier = 4 THEN amount ELSE 0 END), 0) as tier_4_amount
        ")->first();

        $perPage = (int) $request->get('per_page', 50);
        $expenses = $query->paginate($perPage);

        return response()->json([
            'data' => $expenses->items(),
            'current_page' => $expenses->currentPage(),
            'last_page' => $expenses->lastPage(),
            'per_page' => $expenses->perPage(),
            'total' => $expenses->total(),
            'total_amount' => (float) ($tierSummary->total_amount ?? 0),
            'summary' => [
                'total_count' => (int) ($tierSummary->total_count ?? 0),
                'total_amount' => (float) ($tierSummary->total_amount ?? 0),
                'tier_1' => [
                    'count' => (int) ($tierSummary->tier_1_count ?? 0),
                    'amount' => (float) ($tierSummary->tier_1_amount ?? 0),
                    'label' => 'Micro / Petty (< 5k)',
                    'color' => 'green',
                ],
                'tier_2' => [
                    'count' => (int) ($tierSummary->tier_2_count ?? 0),
                    'amount' => (float) ($tierSummary->tier_2_amount ?? 0),
                    'label' => 'Operational (5k - 25k)',
                    'color' => 'blue',
                ],
                'tier_3' => [
                    'count' => (int) ($tierSummary->tier_3_count ?? 0),
                    'amount' => (float) ($tierSummary->tier_3_amount ?? 0),
                    'label' => 'Moderate (25k - 50k)',
                    'color' => 'orange',
                ],
                'tier_4' => [
                    'count' => (int) ($tierSummary->tier_4_count ?? 0),
                    'amount' => (float) ($tierSummary->tier_4_amount ?? 0),
                    'label' => 'High Value (> 50k)',
                    'color' => 'red',
                ],
            ],
        ]);
    }

    /**
     * Create and post a new expense entry immediately (unrestricted amount).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'amount' => 'required|numeric|min:1|max:999999999',
            'description' => 'required|string',
            'payment_method' => 'nullable|string|in:Cash,Bank Transfer,Cheque',
            'payment_reference' => 'nullable|string|max:255',
            'cheque_id' => 'nullable|exists:cheques,id',
            'cheque_no' => 'nullable|string|max:100',
            'cheque_beneficiary' => 'nullable|string|max:255',
            'cheque_issue_date' => 'nullable|date',
            'cheque_cashed_date' => 'nullable|date',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192', // Max 8MB
        ]);

        $receiptUrl = null;
        if ($request->hasFile('receipt')) {
            $path = $request->file('receipt')->store('receipts', 'public');
            $receiptUrl = Storage::url($path);
        }

        $paymentMethod = $request->payment_method ?? 'Cash';
        if ($paymentMethod === 'Cash') {
            $totalPettyCashInflow = (float) \App\Models\Cheque::where('status', 'cashed')
                ->where('purpose', 'petty_cash')
                ->sum('amount');
            $totalDirectCashInflow = (float) \App\Models\CashInflow::sum('amount');
            $totalCashExpenses = (float) Expense::where('payment_method', 'Cash')->sum('amount');
            $availableCash = ($totalPettyCashInflow + $totalDirectCashInflow) - $totalCashExpenses;

            if ((float) $request->amount > $availableCash) {
                return response()->json([
                    'message' => 'Insufficient cash in Cash Drawer.',
                    'errors' => [
                        'amount' => [
                            sprintf(
                                'Cannot pay Rs. %s in cash. Cash Drawer available balance is only Rs. %s. Please cash a replenishment cheque from the bank first.',
                                number_format($request->amount),
                                number_format(max(0, $availableCash))
                            )
                        ]
                    ]
                ], 422);
            }
        }

        $tierInfo = Expense::resolveTier((float) $request->amount);

        // Atomically post expense entry and handle linked cheque
        $expense = DB::transaction(function () use ($request, $user, $receiptUrl, $tierInfo) {
            // Handle Cheque Creation if Cheque payment method and Cheque # provided
            $chequeId = $request->cheque_id;
            if ($request->payment_method === 'Cheque' && !$chequeId && $request->filled('cheque_no')) {
                $cheque = \App\Models\Cheque::create([
                    'cheque_no' => $request->cheque_no,
                    'amount' => $request->amount,
                    'amount_tier' => $tierInfo['tier'],
                    'tier_color' => $tierInfo['color'],
                    'issue_date' => $request->cheque_issue_date ?? now()->toDateString(),
                    'cashed_date' => $request->cheque_cashed_date ?? now()->toDateString(),
                    'status' => 'cashed',
                    'beneficiary' => $request->cheque_beneficiary ?: $request->title,
                    'purpose' => 'vendor_payment',
                    'description' => $request->description,
                    'slip_url' => $receiptUrl,
                    'created_by' => $user->id,
                ]);
                $chequeId = $cheque->id;
            }

            return Expense::create([
                'title' => $request->title,
                'category' => $request->category,
                'amount' => $request->amount,
                'amount_tier' => $tierInfo['tier'],
                'tier_color' => $tierInfo['color'],
                'description' => $request->description,
                'receipt_url' => $receiptUrl,
                'expense_type' => 'general',
                'created_by' => $user->id,
                'status' => 'disbursed', // Immediately active/posted
                'disbursed_at' => now(),
                'disbursed_by' => $user->id,
                'payment_method' => $request->payment_method ?? 'Cash',
                'payment_reference' => $request->payment_reference ?? ($request->cheque_no ? "Cheque #{$request->cheque_no}" : null),
                'cheque_id' => $chequeId,
            ]);
        });

        $expense->load(['creator:id,name,role', 'cheque:id,cheque_no,beneficiary,amount,cashed_date']);

        // Dispatch instant real-time push alert with color tier to all Directors
        FcmService::notifyExpenseCreated($expense);

        return response()->json([
            'message' => 'Expense entry posted successfully.',
            'data' => $expense,
            'tier' => $tierInfo,
        ], 201);
    }

    /**
     * View single expense details.
     */
    public function show($id)
    {
        $expense = Expense::with([
            'creator:id,name,role',
            'cheque:id,cheque_no,beneficiary,amount,cashed_date',
        ])->findOrFail($id);

        return response()->json(['data' => $expense]);
    }

    /**
     * Category-Wise Financial Expense Overview.
     */
    public function overview(Request $request)
    {
        $year = (int) $request->get('year', now()->year);
        $month = $request->get('month'); // optional month (YYYY-MM)

        $query = Expense::query();

        if ($request->filled('expense_type') && $request->expense_type !== 'all') {
            $query->where('expense_type', $request->expense_type);
        }

        if ($month) {
            $start = \Illuminate\Support\Carbon::parse($month . '-01')->startOfMonth()->toDateTimeString();
            $end = \Illuminate\Support\Carbon::parse($month . '-01')->endOfMonth()->toDateTimeString();
            $query->whereBetween('created_at', [$start, $end]);
        } else {
            $query->whereYear('created_at', $year);
        }

        // Aggregate by category
        $records = (clone $query)
            ->selectRaw('category, SUM(amount) as total_amount, COUNT(*) as count')
            ->groupBy('category')
            ->get();

        $allCategories = \App\Models\Category::orderBy('name')->get();
        $categoryMap = [];

        foreach ($allCategories as $cat) {
            $categoryMap[$cat->name] = [
                'name' => $cat->name,
                'description' => $cat->description,
                'total_amount' => 0.0,
                'total_count' => 0,
                'percentage' => 0.0,
                'avg_per_voucher' => 0.0,
            ];
        }

        $grandTotal = 0.0;
        $totalVouchers = 0;

        foreach ($records as $r) {
            $catName = $r->category;
            $amt = (float) $r->total_amount;
            $cnt = (int) $r->count;

            if (!isset($categoryMap[$catName])) {
                $categoryMap[$catName] = [
                    'name' => $catName,
                    'description' => null,
                    'total_amount' => 0.0,
                    'total_count' => 0,
                    'percentage' => 0.0,
                    'avg_per_voucher' => 0.0,
                ];
            }

            $categoryMap[$catName]['total_amount'] = $amt;
            $categoryMap[$catName]['total_count'] = $cnt;
            $categoryMap[$catName]['avg_per_voucher'] = $cnt > 0 ? round($amt / $cnt, 2) : 0.0;

            $grandTotal += $amt;
            $totalVouchers += $cnt;
        }

        $categoriesList = array_values($categoryMap);
        foreach ($categoriesList as &$c) {
            $c['percentage'] = $grandTotal > 0 ? round(($c['total_amount'] / $grandTotal) * 100, 2) : 0.0;
        }
        unset($c);

        usort($categoriesList, fn($a, $b) => $b['total_amount'] <=> $a['total_amount']);

        $highestCategory = !empty($categoriesList) && $categoriesList[0]['total_amount'] > 0
            ? ['name' => $categoriesList[0]['name'], 'amount' => $categoriesList[0]['total_amount'], 'percentage' => $categoriesList[0]['percentage']]
            : null;

        return response()->json([
            'year' => $year,
            'month' => $month,
            'grand_total' => $grandTotal,
            'total_vouchers' => $totalVouchers,
            'highest_category' => $highestCategory,
            'active_categories_count' => count(array_filter($categoriesList, fn($c) => $c['total_amount'] > 0)),
            'avg_per_voucher' => $totalVouchers > 0 ? round($grandTotal / $totalVouchers, 2) : 0.0,
            'categories' => $categoriesList,
        ]);
    }

    /**
     * Monthly Stats & Tier Breakdown.
     */
    public function stats(Request $request)
    {
        $startOfMonth = now()->startOfMonth()->toDateTimeString();
        $endOfMonth = now()->endOfMonth()->toDateTimeString();

        $statsQuery = Expense::query();
        if ($request->filled('expense_type') && $request->expense_type !== 'all') {
            $statsQuery->where('expense_type', $request->expense_type);
        }

        $stats = $statsQuery->selectRaw("
            COUNT(*) as total_vouchers,
            COALESCE(SUM(amount), 0) as total_spent,
            COUNT(CASE WHEN amount_tier = 1 THEN 1 END) as tier_1_count,
            COALESCE(SUM(CASE WHEN amount_tier = 1 THEN amount ELSE 0 END), 0) as tier_1_amount,
            COUNT(CASE WHEN amount_tier = 2 THEN 1 END) as tier_2_count,
            COALESCE(SUM(CASE WHEN amount_tier = 2 THEN amount ELSE 0 END), 0) as tier_2_amount,
            COUNT(CASE WHEN amount_tier = 3 THEN 1 END) as tier_3_count,
            COALESCE(SUM(CASE WHEN amount_tier = 3 THEN amount ELSE 0 END), 0) as tier_3_amount,
            COUNT(CASE WHEN amount_tier = 4 THEN 1 END) as tier_4_count,
            COALESCE(SUM(CASE WHEN amount_tier = 4 THEN amount ELSE 0 END), 0) as tier_4_amount
        ")->whereBetween('created_at', [$startOfMonth, $endOfMonth])->first();

        return response()->json([
            'month' => now()->format('F Y'),
            'total_vouchers' => (int) ($stats->total_vouchers ?? 0),
            'total_spent' => (float) ($stats->total_spent ?? 0),
            'tiers' => [
                'tier_1' => ['count' => (int) $stats->tier_1_count, 'amount' => (float) $stats->tier_1_amount, 'color' => 'green', 'label' => 'Micro / Petty (< 5k)'],
                'tier_2' => ['count' => (int) $stats->tier_2_count, 'amount' => (float) $stats->tier_2_amount, 'color' => 'blue', 'label' => 'Operational (5k - 25k)'],
                'tier_3' => ['count' => (int) $stats->tier_3_count, 'amount' => (float) $stats->tier_3_amount, 'color' => 'orange', 'label' => 'Moderate (25k - 50k)'],
                'tier_4' => ['count' => (int) $stats->tier_4_count, 'amount' => (float) $stats->tier_4_amount, 'color' => 'red', 'label' => 'High Value (> 50k)'],
            ]
        ]);
    }

    /**
     * Export Filtered Expenses as Executive A4 PDF.
     */
    public function exportPdf(Request $request, DocumentPdfService $pdfService)
    {
        $query = Expense::with([
            'creator:id,name,role',
            'cheque:id,cheque_no,beneficiary',
        ])->latest('created_at');

        if ($request->filled('tier')) {
            $query->where('amount_tier', (int) $request->tier);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('date')) {
            $date = $request->date;
            $query->whereBetween('created_at', [$date . ' 00:00:00', $date . ' 23:59:59']);
        } elseif ($request->filled('month')) {
            $month = $request->month;
            $start = \Illuminate\Support\Carbon::parse($month . '-01')->startOfMonth()->toDateTimeString();
            $end = \Illuminate\Support\Carbon::parse($month . '-01')->endOfMonth()->toDateTimeString();
            $query->whereBetween('created_at', [$start, $end]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('voucher_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('expense_type') && $request->expense_type !== 'all') {
            $query->where('expense_type', $request->expense_type);
        }

        $expenses = $query->get();
        $totalAmount = (float) $expenses->sum('amount');

        $tier1 = $expenses->where('amount_tier', 1);
        $tier2 = $expenses->where('amount_tier', 2);
        $tier3 = $expenses->where('amount_tier', 3);
        $tier4 = $expenses->where('amount_tier', 4);

        $category = $request->category;
        $month = $request->month;
        $date = $request->date;

        if ($category && $month) {
            $docTitle = strtoupper($category) . " EXPENDITURE AUDIT REPORT ({$month})";
        } elseif ($category) {
            $docTitle = strtoupper($category) . " EXPENSE AUDIT REPORT";
        } elseif ($month) {
            $docTitle = "INSTITUTIONAL EXPENSES LEDGER REPORT ({$month})";
        } elseif ($date) {
            $docTitle = "DAILY EXPENSE AUDIT REPORT ({$date})";
        } else {
            $docTitle = "INSTITUTIONAL EXPENSES AUDIT REPORT";
        }

        $filename = "Expense_Report_" . ($category ? \Illuminate\Support\Str::slug($category) . '_' : '') . ($month ?? $date ?? date('Y-m-d')) . ".pdf";

        return $pdfService->download('pdf.expense_report', [
            'lang' => 'en',
            'doc_title' => $docTitle,
            'doc_ref' => 'NSSE-EXP-' . ($month ?? $date ?? date('Y-m')),
            'doc_date' => date('d-M-Y'),
            'category' => $category,
            'month' => $month,
            'expenses' => $expenses,
            'total_amount' => $totalAmount,
            'summary' => [
                'total_count' => count($expenses),
                'total_amount' => $totalAmount,
                'tier_1_count' => $tier1->count(),
                'tier_1_amount' => (float) $tier1->sum('amount'),
                'tier_2_count' => $tier2->count(),
                'tier_2_amount' => (float) $tier2->sum('amount'),
                'tier_3_count' => $tier3->count(),
                'tier_3_amount' => (float) $tier3->sum('amount'),
                'tier_4_count' => $tier4->count(),
                'tier_4_amount' => (float) $tier4->sum('amount'),
            ],
        ], $filename);
    }
}
