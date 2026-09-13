<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StaffMemberController extends Controller
{
    protected $authorizedRoles = ['hr', 'principal', 'super_admin', 'admin', 'chairman'];

    /**
     * List Non-Teaching Staff members.
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, array_merge($this->authorizedRoles, ['accountant', 'hr_accountant', 'teacher']))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = StaffMember::with(['user:id,name,email,role,is_active', 'creator:id,name,role']);

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('designation', 'like', "%{$search}%")
                  ->orWhere('cnic', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('designation') && $request->designation !== 'all') {
            $query->where('designation', $request->designation);
        }

        $staff = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'data' => $staff,
            'total' => $staff->count(),
            'active_count' => $staff->where('status', 'active')->count(),
            'on_leave_count' => $staff->where('status', 'on_leave')->count(),
            'inactive_count' => $staff->where('status', 'inactive')->count(),
        ]);
    }

    /**
     * Create / Onboard Non-Teaching Staff.
     */
    public function store(Request $request)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, $this->authorizedRoles)) {
            return response()->json(['message' => 'Unauthorized. Only HR, Principal, or Admin can register staff.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'designation' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'father_name' => 'nullable|string|max:255',
            'cnic' => 'nullable|string|max:30',
            'contact_number' => 'nullable|string|max:30',
            'emergency_number' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
            'date_of_joining' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0|max:99999999',
            'allowances' => 'nullable',
            'payment_method' => 'nullable|in:bank,cash',
            'bank_name' => 'nullable|string|max:100',
            'account_title' => 'nullable|string|max:150',
            'account_number' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,on_leave,inactive',
            'profile_picture' => 'nullable|image|max:8192',
        ]);

        DB::beginTransaction();
        try {
            $user = null;
            $status = $request->input('status', 'active');
            $canLogin = ($status !== 'inactive');

            // If an email is provided, optionally create a portal user with role non_teaching_staff
            if ($request->filled('email')) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => strtolower(trim($request->email)),
                    'role' => 'non_teaching_staff',
                    'is_active' => $canLogin,
                ]);
            }

            $picturePath = null;
            if ($request->hasFile('profile_picture')) {
                $picturePath = $request->file('profile_picture')->store('staff_profiles', 'public');
            }

            $allowances = $this->parseAllowances($request->allowances);

            $staff = StaffMember::create([
                'user_id' => $user ? $user->id : null,
                'created_by' => $currentUser->id,
                'name' => $request->name,
                'designation' => $request->designation,
                'father_name' => $request->father_name,
                'cnic' => $request->cnic,
                'contact_number' => $request->contact_number,
                'emergency_number' => $request->emergency_number,
                'address' => $request->address,
                'city' => $request->city,
                'date_of_joining' => $request->date_of_joining,
                'salary' => $request->salary ? (float) $request->salary : 0,
                'allowances' => $allowances,
                'payment_method' => $request->input('payment_method', 'cash'),
                'bank_name' => $request->bank_name,
                'account_title' => $request->account_title,
                'account_number' => $request->account_number,
                'status' => $status,
                'profile_picture' => $picturePath,
            ]);

            DB::commit();

            return response()->json([
                'message' => "Staff member {$staff->name} registered successfully.",
                'data' => $staff->load('user:id,name,email,role'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to add staff member: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Show single staff record.
     */
    public function show($id)
    {
        $staff = StaffMember::with(['user:id,name,email,role,is_active', 'creator:id,name,role'])->findOrFail($id);
        return response()->json(['data' => $staff]);
    }

    /**
     * Update Staff record.
     */
    public function update(Request $request, $id)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, $this->authorizedRoles)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $staff = StaffMember::findOrFail($id);

        // Principal profile can be edited by Director / Admin only
        $isPrincipal = ($staff->user && $staff->user->role === 'principal') 
            || stripos($staff->designation ?? '', 'principal') !== false;

        if ($isPrincipal && $currentUser->role === 'principal') {
            return response()->json([
                'message' => 'The Principal profile is protected and can only be edited by the Executive Director.'
            ], 403);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'designation' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . ($staff->user_id ?? 0),
            'father_name' => 'nullable|string|max:255',
            'cnic' => 'nullable|string|max:30',
            'contact_number' => 'nullable|string|max:30',
            'emergency_number' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
            'date_of_joining' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0|max:99999999',
            'allowances' => 'nullable',
            'payment_method' => 'nullable|in:bank,cash',
            'bank_name' => 'nullable|string|max:100',
            'account_title' => 'nullable|string|max:150',
            'account_number' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,on_leave,inactive',
            'profile_picture' => 'nullable|image|max:8192',
        ]);

        if ($request->hasFile('profile_picture')) {
            if ($staff->profile_picture) {
                Storage::disk('public')->delete($staff->profile_picture);
            }
            $staff->profile_picture = $request->file('profile_picture')->store('staff_profiles', 'public');
        }

        if ($request->has('allowances')) {
            $staff->allowances = $this->parseAllowances($request->allowances);
        }

        if ($request->has('name')) $staff->name = $request->name;
        if ($request->has('designation')) $staff->designation = $request->designation;
        if ($request->has('father_name')) $staff->father_name = $request->father_name;
        if ($request->has('cnic')) $staff->cnic = $request->cnic;
        if ($request->has('contact_number')) $staff->contact_number = $request->contact_number;
        if ($request->has('emergency_number')) $staff->emergency_number = $request->emergency_number;
        if ($request->has('address')) $staff->address = $request->address;
        if ($request->has('city')) $staff->city = $request->city;
        if ($request->has('date_of_joining')) $staff->date_of_joining = $request->date_of_joining;
        if ($request->has('salary')) $staff->salary = (float) $request->salary;
        if ($request->has('payment_method')) $staff->payment_method = $request->payment_method;
        if ($request->has('bank_name')) $staff->bank_name = $request->bank_name;
        if ($request->has('account_title')) $staff->account_title = $request->account_title;
        if ($request->has('account_number')) $staff->account_number = $request->account_number;
        if ($request->has('status')) $staff->status = $request->status;

        $staff->save();

        if ($staff->user) {
            $staff->user->name = $staff->name;
            if ($request->filled('email')) {
                $staff->user->email = strtolower(trim($request->email));
            }
            if ($request->has('status')) {
                $staff->user->is_active = ($staff->status !== 'inactive');
            }
            $staff->user->save();
        }

        return response()->json([
            'message' => "Staff member {$staff->name} updated successfully.",
            'data' => $staff->load('user:id,name,email,role'),
        ]);
    }

    /**
     * Update Staff status (active, on_leave, inactive)
     */
    public function updateStatus(Request $request, $id)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, $this->authorizedRoles)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'status' => 'required|in:active,on_leave,inactive',
        ]);

        $staff = StaffMember::findOrFail($id);
        $staff->status = $request->status;
        $staff->save();

        if ($staff->user) {
            $staff->user->is_active = ($request->status !== 'inactive');
            $staff->user->save();
        }

        return response()->json([
            'message' => "Staff {$staff->name} status updated to {$staff->status}.",
            'data' => $staff->load('user:id,name,email,role'),
        ]);
    }

    /**
     * Delete Staff record.
     */
    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, $this->authorizedRoles)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $staff = StaffMember::findOrFail($id);
        $name = $staff->name;

        if ($staff->profile_picture) {
            Storage::disk('public')->delete($staff->profile_picture);
        }

        if ($staff->user) {
            $staff->user->delete();
        }

        $staff->delete();

        return response()->json(['message' => "Staff member {$name} removed successfully."]);
    }

    private function parseAllowances($allowances): ?array
    {
        if (empty($allowances)) {
            return null;
        }
        if (is_string($allowances)) {
            $decoded = json_decode($allowances, true);
            return is_array($decoded) ? $decoded : null;
        }
        if (is_array($allowances)) {
            return $allowances;
        }
        return null;
    }
}
