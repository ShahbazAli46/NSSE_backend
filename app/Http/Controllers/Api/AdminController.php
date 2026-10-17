<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * List all users (Super Admin / Chairman only).
     */
    public function users(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized. Super Admin or Chairman access only.'], 403);
        }

        $query = User::latest();

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(20);

        return response()->json($users);
    }

    /**
     * Create a new institutional user (Principal, Accountant, Director, etc.).
     */
    public function createUser(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized. Super Admin or Chairman access only.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|string|in:admin,super_admin,chairman,director,principal,accountant,hr_accountant,hr,office_admin,teacher,staff,support_staff,parent,student',
        ], [
            'email.unique' => 'A user with this email already exists in the system.',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'password' => null, // Passwordless OTP
            'email_verified_at' => now(),
        ]);

        try {
            $user->assignRole($request->role);
        } catch (\Exception $e) {
            // Role assignment fallback if spatie role not seeded
        }

        return response()->json([
            'message' => "User '{$user->name}' registered successfully with role '{$user->role}'.",
            'user' => $user,
        ], 201);
    }

    /**
     * Update an existing user's role or name.
     */
    public function updateUser(Request $request, $id)
    {
        if (!in_array($request->user()->role, ['admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized. Super Admin or Chairman access only.'], 403);
        }

        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'role' => 'required|string|in:admin,super_admin,chairman,director,principal,accountant,hr_accountant,hr,office_admin,teacher,staff,support_staff,parent,student',
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
        ]);

        try {
            $user->syncRoles([$request->role]);
        } catch (\Exception $e) {
            // Fallback
        }

        return response()->json([
            'message' => "User '{$user->name}' updated successfully.",
            'user' => $user,
        ]);
    }

    /**
     * Delete a user from the system.
     */
    public function deleteUser(Request $request, $id)
    {
        if (!in_array($request->user()->role, ['admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized. Super Admin or Chairman access only.'], 403);
        }

        $user = User::findOrFail($id);

        // Prevent self-deletion
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own executive account.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }

    /**
     * Activate or Deactivate a user.
     */
    public function toggleStatus(Request $request, $id)
    {
        if (!in_array($request->user()->role, ['admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized. Super Admin or Chairman access only.'], 403);
        }

        $user = User::findOrFail($id);

        // Prevent self-deactivation
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot deactivate your own executive account.'], 422);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        // If deactivated, revoke existing tokens so their active session ends immediately
        if (!$user->is_active) {
            $user->tokens()->delete();
        }

        $statusText = $user->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'message' => "User '{$user->name}' has been {$statusText}.",
            'user' => $user,
        ]);
    }

    /**
     * Super Admin Master System Statistics.
     */
    public function systemStats(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'super_admin', 'chairman'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $roleCounts = User::select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role');

        return response()->json([
            'total_users' => User::count(),
            'role_counts' => $roleCounts,
            'smtp_status' => 'Active (smtp.hostinger.com:465)',
            'broadcasting_status' => 'Active (Pusher Cloud ap3)',
            'database_status' => 'MySQL (nsse_portal)',
        ]);
    }
}
