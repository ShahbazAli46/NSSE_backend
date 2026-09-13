<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeacherController extends Controller
{
    /**
     * List all Teachers (For Principal, Admin, Chairman).
     */
    public function index(Request $request)
    {
        $query = Teacher::with(['user:id,name,email,role,is_active', 'creator:id,name,role']);

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('father_name', 'like', "%{$search}%")
                  ->orWhere('cnic', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%")
                  ->orWhere('qualification', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $teachers = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'data' => $teachers,
            'summary' => [
                'total_teachers' => $teachers->count(),
                'active_teachers' => $teachers->where('status', 'active')->count(),
                'total_payroll' => $teachers->where('status', 'active')->sum('gross_salary'),
            ]
        ]);
    }

    /**
     * Principal / Admin registers a new Teacher.
     */
    public function store(Request $request)
    {
        $currentUser = $request->user();

        if (!in_array($currentUser->role, ['hr', 'principal', 'super_admin', 'admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized. Only the Principal or Administrator can add teachers.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'father_name' => 'nullable|string|max:255',
            'cnic' => 'nullable|string|max:30',
            'contact_number' => 'nullable|string|max:30',
            'emergency_number' => 'nullable|string|max:30',
            'qualification' => 'nullable|string|max:255',
            'teaching_since' => 'nullable|string|max:50',
            'profile_picture' => 'nullable|image|max:8192',
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
        ]);

        DB::beginTransaction();
        try {
            $status = $request->input('status', 'active');
            $canLogin = ($status !== 'inactive');

            // Find or create User for Teacher login
            $user = User::where('email', strtolower(trim($request->email)))->first();
            if (!$user) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => strtolower(trim($request->email)),
                    'role' => 'teacher',
                    'is_active' => $canLogin,
                ]);
            } else {
                if ($user->role === 'director') {
                    $user->role = 'teacher';
                }
                $user->is_active = $canLogin;
                $user->save();
            }

            // Handle Profile Picture Upload
            $picturePath = null;
            if ($request->hasFile('profile_picture')) {
                $picturePath = $request->file('profile_picture')->store('teacher_profiles', 'public');
            }

            // Handle Allowances parsing
            $allowances = $this->parseAllowances($request->allowances);

            $teacher = Teacher::create([
                'user_id' => $user->id,
                'created_by' => $currentUser->id,
                'name' => $request->name,
                'father_name' => $request->father_name,
                'cnic' => $request->cnic,
                'contact_number' => $request->contact_number,
                'emergency_number' => $request->emergency_number,
                'qualification' => $request->qualification,
                'teaching_since' => $request->teaching_since,
                'profile_picture' => $picturePath,
                'address' => $request->address,
                'city' => $request->city,
                'date_of_joining' => $request->date_of_joining,
                'salary' => $request->salary ? (float) $request->salary : 0,
                'allowances' => $allowances,
                'payment_method' => $request->input('payment_method', 'bank'),
                'bank_name' => $request->bank_name,
                'account_title' => $request->account_title,
                'account_number' => $request->account_number,
                'status' => $status,
            ]);

            DB::commit();

            return response()->json([
                'message' => "Teacher {$teacher->name} registered successfully.",
                'data' => $teacher->load('user:id,name,email,role'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to add teacher: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Show single Teacher record.
     */
    public function show($id)
    {
        $teacher = Teacher::with(['user:id,name,email,role,is_active', 'creator:id,name,role'])->findOrFail($id);
        return response()->json(['data' => $teacher]);
    }

    /**
     * Principal / Admin updates teacher.
     */
    public function update(Request $request, $id)
    {
        $currentUser = $request->user();

        if (!in_array($currentUser->role, ['hr', 'principal', 'super_admin', 'admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $teacher = Teacher::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . ($teacher->user_id ?? 0),
            'father_name' => 'nullable|string|max:255',
            'cnic' => 'nullable|string|max:30',
            'contact_number' => 'nullable|string|max:30',
            'emergency_number' => 'nullable|string|max:30',
            'qualification' => 'nullable|string|max:255',
            'teaching_since' => 'nullable|string|max:50',
            'profile_picture' => 'nullable|image|max:8192',
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
        ]);

        if ($request->hasFile('profile_picture')) {
            if ($teacher->profile_picture) {
                Storage::disk('public')->delete($teacher->profile_picture);
            }
            $teacher->profile_picture = $request->file('profile_picture')->store('teacher_profiles', 'public');
        }

        if ($request->has('allowances')) {
            $teacher->allowances = $this->parseAllowances($request->allowances);
        }

        if ($request->has('name')) $teacher->name = $request->name;
        if ($request->has('father_name')) $teacher->father_name = $request->father_name;
        if ($request->has('cnic')) $teacher->cnic = $request->cnic;
        if ($request->has('contact_number')) $teacher->contact_number = $request->contact_number;
        if ($request->has('emergency_number')) $teacher->emergency_number = $request->emergency_number;
        if ($request->has('qualification')) $teacher->qualification = $request->qualification;
        if ($request->has('teaching_since')) $teacher->teaching_since = $request->teaching_since;
        if ($request->has('address')) $teacher->address = $request->address;
        if ($request->has('city')) $teacher->city = $request->city;
        if ($request->has('date_of_joining')) $teacher->date_of_joining = $request->date_of_joining;
        if ($request->has('salary')) {
            $teacher->salary = (float) $request->salary;
        }
        if ($request->has('payment_method')) {
            $teacher->payment_method = $request->payment_method;
        }
        if ($request->has('bank_name')) {
            $teacher->bank_name = $request->bank_name;
        }
        if ($request->has('account_title')) {
            $teacher->account_title = $request->account_title;
        }
        if ($request->has('account_number')) {
            $teacher->account_number = $request->account_number;
        }
        if ($request->has('status')) {
            $teacher->status = $request->status;
        }
        $teacher->save();

        if ($teacher->user) {
            $teacher->user->name = $teacher->name;
            if ($request->filled('email')) {
                $teacher->user->email = strtolower(trim($request->email));
            }
            if ($request->has('status')) {
                $teacher->user->is_active = ($teacher->status !== 'inactive');
            }
            $teacher->user->save();
        }

        return response()->json([
            'message' => "Teacher {$teacher->name} updated successfully.",
            'data' => $teacher->load('user:id,name,email,role'),
        ]);
    }

    /**
     * Update teacher status (active, on_leave, inactive)
     */
    public function updateStatus(Request $request, $id)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, ['hr', 'principal', 'super_admin', 'admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'status' => 'required|in:active,on_leave,inactive',
        ]);

        $teacher = Teacher::findOrFail($id);
        $teacher->status = $request->status;
        $teacher->save();

        if ($teacher->user) {
            $teacher->user->is_active = ($request->status !== 'inactive');
            $teacher->user->save();
        }

        return response()->json([
            'message' => "Teacher {$teacher->name} status updated to {$teacher->status}.",
            'data' => $teacher->load('user:id,name,email,role'),
        ]);
    }

    /**
     * Quick status toggle (Active <-> Inactive)
     */
    public function toggleStatus(Request $request, $id)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, ['hr', 'principal', 'super_admin', 'admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $teacher = Teacher::findOrFail($id);
        $teacher->status = ($teacher->status === 'active') ? 'inactive' : 'active';
        $teacher->save();

        if ($teacher->user) {
            $teacher->user->is_active = ($teacher->status === 'active');
            $teacher->user->save();
        }

        return response()->json([
            'message' => "Teacher {$teacher->name} status set to {$teacher->status}.",
            'data' => $teacher->load('user:id,name,email,role'),
        ]);
    }

    /**
     * Delete teacher record.
     */
    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();
        if (!in_array($currentUser->role, ['hr', 'principal', 'super_admin', 'admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $teacher = Teacher::findOrFail($id);
        $name = $teacher->name;

        if ($teacher->profile_picture) {
            Storage::disk('public')->delete($teacher->profile_picture);
        }

        if ($teacher->user) {
            $teacher->user->delete();
        }

        $teacher->delete();

        return response()->json([
            'message' => "Teacher {$name} has been removed successfully.",
        ]);
    }

    /**
     * Get profile of currently logged-in Teacher.
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();
        $teacher = Teacher::with(['user:id,name,email,role'])->where('user_id', $user->id)->first();

        if (!$teacher) {
            // Auto-create blank teacher record for teacher user if not exists
            $teacher = Teacher::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'status' => 'active',
            ]);
        }

        return response()->json(['data' => $teacher]);
    }

    /**
     * Teacher updates their own personal profile information.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $teacher = Teacher::where('user_id', $user->id)->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'father_name' => 'nullable|string|max:255',
            'cnic' => 'nullable|string|max:30',
            'contact_number' => 'nullable|string|max:30',
            'emergency_number' => 'nullable|string|max:30',
            'qualification' => 'nullable|string|max:255',
            'teaching_since' => 'nullable|string|max:50',
            'profile_picture' => 'nullable|image|max:8192',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
        ]);

        if ($request->hasFile('profile_picture')) {
            if ($teacher->profile_picture) {
                Storage::disk('public')->delete($teacher->profile_picture);
            }
            $teacher->profile_picture = $request->file('profile_picture')->store('teacher_profiles', 'public');
        }

        $teacher->name = $request->name;
        $teacher->father_name = $request->father_name;
        $teacher->cnic = $request->cnic;
        $teacher->contact_number = $request->contact_number;
        $teacher->emergency_number = $request->emergency_number;
        $teacher->qualification = $request->qualification;
        $teacher->teaching_since = $request->teaching_since;
        $teacher->address = $request->address;
        $teacher->city = $request->city;
        $teacher->save();

        $user->name = $teacher->name;
        if ($request->filled('email')) {
            $user->email = strtolower(trim($request->email));
        }
        $user->save();

        return response()->json([
            'message' => 'Your profile information has been updated successfully.',
            'data' => $teacher->load('user:id,name,email,role'),
        ]);
    }

    /**
     * Helper to safely parse allowances JSON or array.
     */
    private function parseAllowances($raw): ?array
    {
        if (empty($raw)) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
