<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cheque;
use App\Services\FcmService;
use App\Services\DocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChequeController extends Controller
{
    /**
     * List cheques with filters.
     */
    public function index(Request $request)
    {
        $query = Cheque::with('creator:id,name,role')->orderBy('issue_date', 'asc')->orderBy('created_at', 'asc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('purpose')) {
            $query->where('purpose', $request->purpose);
        }

        if ($request->filled('date')) {
            $date = $request->date;
            $query->where(function ($q) use ($date) {
                $q->where('issue_date', $date)
                  ->orWhere('cashed_date', $date);
            });
        } elseif ($request->filled('month')) {
            $month = $request->month; // e.g. "2026-08"
            $start = \Illuminate\Support\Carbon::parse($month . '-01')->startOfMonth()->toDateString();
            $end = \Illuminate\Support\Carbon::parse($month . '-01')->endOfMonth()->toDateString();
            $startTime = \Illuminate\Support\Carbon::parse($month . '-01')->startOfMonth()->toDateTimeString();
            $endTime = \Illuminate\Support\Carbon::parse($month . '-01')->endOfMonth()->toDateTimeString();

            $query->where(function ($q) use ($start, $end, $startTime, $endTime) {
                $q->whereBetween('issue_date', [$start, $end])
                  ->orWhereBetween('cashed_date', [$start, $end])
                  ->orWhereBetween('created_at', [$startTime, $endTime]);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('cheque_no', 'like', "%{$search}%")
                  ->orWhere('beneficiary', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $summary = (clone $query)->selectRaw("
            COUNT(*) as total_count,
            COALESCE(SUM(amount), 0) as total_amount,
            COUNT(CASE WHEN status = 'cashed' THEN 1 END) as cashed_count,
            COALESCE(SUM(CASE WHEN status = 'cashed' THEN amount ELSE 0 END), 0) as cashed_amount,
            COUNT(CASE WHEN status = 'issued' THEN 1 END) as pending_count,
            COALESCE(SUM(CASE WHEN status = 'issued' THEN amount ELSE 0 END), 0) as pending_amount
        ")->first();

        $cheques = $query->paginate((int) $request->get('per_page', 50));

        return response()->json([
            'data' => $cheques->items(),
            'current_page' => $cheques->currentPage(),
            'last_page' => $cheques->lastPage(),
            'total' => $cheques->total(),
            'summary' => [
                'total_count' => (int) $summary->total_count,
                'total_amount' => (float) $summary->total_amount,
                'cashed_count' => (int) $summary->cashed_count,
                'cashed_amount' => (float) $summary->cashed_amount,
                'pending_count' => (int) $summary->pending_count,
                'pending_amount' => (float) $summary->pending_amount,
            ],
        ]);
    }

    /**
     * Issue a new cheque.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'cheque_no' => 'required|string|max:100|unique:cheques,cheque_no',
            'amount' => 'required|numeric|min:1|max:999999999',
            'issue_date' => 'required|date',
            'beneficiary' => 'required|string|max:255',
            'purpose' => 'required|string|in:petty_cash,vendor_payment,salary,infrastructure,utility,other',
            'description' => 'nullable|string',
            'slip' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
            'is_already_cashed' => 'nullable|boolean',
            'cashed_date' => 'nullable|date',
        ]);

        $slipUrl = null;
        if ($request->hasFile('slip')) {
            $path = $request->file('slip')->store('cheques', 'public');
            $slipUrl = Storage::url($path);
        }

        $tierInfo = Cheque::resolveTier((float) $request->amount);
        $isAlreadyCashed = $request->boolean('is_already_cashed');

        $cheque = Cheque::create([
            'cheque_no' => $request->cheque_no,
            'amount' => $request->amount,
            'amount_tier' => $tierInfo['tier'],
            'tier_color' => $tierInfo['color'],
            'issue_date' => $request->issue_date,
            'cashed_date' => $isAlreadyCashed ? ($request->cashed_date ?? $request->issue_date) : null,
            'status' => $isAlreadyCashed ? 'cashed' : 'issued',
            'beneficiary' => $request->beneficiary,
            'purpose' => $request->purpose,
            'description' => $request->description,
            'slip_url' => $slipUrl,
            'created_by' => $user->id,
        ]);

        $cheque->load('creator:id,name,role');

        if ($isAlreadyCashed) {
            FcmService::notifyChequeCashed($cheque);
        }

        return response()->json([
            'message' => 'Cheque registered successfully.',
            'data' => $cheque,
        ], 201);
    }

    /**
     * Mark an issued cheque as Cashed Out / Cleared at the bank.
     */
    public function cashOut(Request $request, $id)
    {
        $cheque = Cheque::findOrFail($id);

        $request->validate([
            'cashed_date' => 'required|date',
            'slip' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        $updateData = [
            'status' => 'cashed',
            'cashed_date' => $request->cashed_date,
        ];

        if ($request->hasFile('slip')) {
            $path = $request->file('slip')->store('cheques', 'public');
            $updateData['slip_url'] = Storage::url($path);
        }

        $cheque->update($updateData);
        $cheque->load('creator:id,name,role');

        // Dispatch instant alert to Directors
        FcmService::notifyChequeCashed($cheque);

        return response()->json([
            'message' => 'Cheque marked as cashed successfully.',
            'data' => $cheque,
        ]);
    }

    /**
     * Export Cheques Audit Report as A4 PDF.
     */
    public function exportPdf(Request $request, DocumentPdfService $pdfService)
    {
        $query = Cheque::with('creator:id,name,role')->latest('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('purpose')) {
            $query->where('purpose', $request->purpose);
        }

        if ($request->filled('date')) {
            $date = $request->date;
            $query->where(function ($q) use ($date) {
                $q->where('issue_date', $date)
                  ->orWhere('cashed_date', $date);
            });
        } elseif ($request->filled('month')) {
            $month = $request->month; // e.g. "2026-08"
            $start = \Illuminate\Support\Carbon::parse($month . '-01')->startOfMonth()->toDateString();
            $end = \Illuminate\Support\Carbon::parse($month . '-01')->endOfMonth()->toDateString();
            $startTime = \Illuminate\Support\Carbon::parse($month . '-01')->startOfMonth()->toDateTimeString();
            $endTime = \Illuminate\Support\Carbon::parse($month . '-01')->endOfMonth()->toDateTimeString();

            $query->where(function ($q) use ($start, $end, $startTime, $endTime) {
                $q->whereBetween('issue_date', [$start, $end])
                  ->orWhereBetween('cashed_date', [$start, $end])
                  ->orWhereBetween('created_at', [$startTime, $endTime]);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('cheque_no', 'like', "%{$search}%")
                  ->orWhere('beneficiary', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $cheques = $query->get();
        $totalAmount = (float) $cheques->sum('amount');
        $cashedCheques = $cheques->where('status', 'cashed');
        $pendingCheques = $cheques->where('status', 'issued');

        $month = $request->month;
        $docTitle = $month ? "OFFICIAL BANK CHEQUE REGISTER AUDIT REPORT ({$month})" : "OFFICIAL BANK CHEQUE REGISTER AUDIT REPORT";
        $filename = "Cheque_Register_" . ($month ?? date('Y-m')) . ".pdf";

        return $pdfService->download('pdf.cheque_report', [
            'lang' => 'en',
            'doc_title' => $docTitle,
            'doc_ref' => 'NSSE-CHQ-' . ($month ?? date('Y-m')),
            'doc_date' => date('d-M-Y'),
            'month' => $month,
            'cheques' => $cheques,
            'total_amount' => $totalAmount,
            'summary' => [
                'total_count' => count($cheques),
                'total_amount' => $totalAmount,
                'cashed_count' => $cashedCheques->count(),
                'cashed_amount' => (float) $cashedCheques->sum('amount'),
                'pending_count' => $pendingCheques->count(),
                'pending_amount' => (float) $pendingCheques->sum('amount'),
            ],
        ], $filename);
    }

    /**
     * Delete cheque (Admin only).
     */
    public function destroy(Request $request, $id)
    {
        $cheque = Cheque::findOrFail($id);
        $cheque->delete();

        return response()->json(['message' => 'Cheque record removed.']);
    }
}
