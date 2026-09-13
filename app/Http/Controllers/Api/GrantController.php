<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GrantController extends Controller
{
    /**
     * List all government grants / tranches received.
     */
    public function index(Request $request)
    {
        $query = Grant::with('creator:id,name,role')->orderBy('deposit_date', 'asc')->orderBy('created_at', 'asc');

        if ($request->filled('month')) {
            $month = $request->month;
            $start = \Illuminate\Support\Carbon::parse($month . '-01')->startOfMonth()->toDateString();
            $end = \Illuminate\Support\Carbon::parse($month . '-01')->endOfMonth()->toDateString();
            $query->whereBetween('deposit_date', [$start, $end]);
        } elseif ($request->filled('year')) {
            $query->whereYear('deposit_date', $request->year);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('reference_no', 'like', "%{$search}%")
                  ->orWhere('bank_account', 'like', "%{$search}%");
            });
        }

        $totalGrants = (float) Grant::sum('amount');
        $grants = $query->paginate((int) $request->get('per_page', 50));

        return response()->json([
            'data' => $grants->items(),
            'total_grants_amount' => $totalGrants,
            'current_page' => $grants->currentPage(),
            'last_page' => $grants->lastPage(),
            'total' => $grants->total(),
        ]);
    }

    /**
     * Record a new government grant deposit.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1|max:999999999',
            'deposit_date' => 'required|date',
            'bank_account' => 'nullable|string|max:255',
            'reference_no' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'slip' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        $slipUrl = null;
        if ($request->hasFile('slip')) {
            $path = $request->file('slip')->store('grants', 'public');
            $slipUrl = Storage::url($path);
        }

        $grant = Grant::create([
            'title' => $request->title,
            'amount' => $request->amount,
            'deposit_date' => $request->deposit_date,
            'bank_account' => $request->bank_account ?? 'Main School Bank Account',
            'reference_no' => $request->reference_no,
            'slip_url' => $slipUrl,
            'notes' => $request->notes,
            'created_by' => $user->id,
        ]);

        $grant->load('creator:id,name,role');

        // Dispatch instant alert to Directors
        FcmService::notifyGrantReceived($grant);

        return response()->json([
            'message' => 'Government grant recorded successfully.',
            'data' => $grant,
        ], 201);
    }

    /**
     * Delete grant (Admins only).
     */
    public function destroy(Request $request, $id)
    {
        $grant = Grant::findOrFail($id);
        $grant->delete();

        return response()->json(['message' => 'Grant record removed.']);
    }
}
