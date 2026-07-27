<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    private function passwordStrengthScore(string $password): int
    {
        $score = 0;
        if (strlen($password) >= 8) $score++;
        if (strlen($password) >= 12) $score++;
        if (preg_match('/[A-Z]/', $password)) $score++;
        if (preg_match('/[0-9]/', $password)) $score++;
        if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
        return $score;
    }

    private function passwordStrengthRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if ($this->passwordStrengthScore($value) < 3) {
                $fail('Password is too weak. Use at least 8 characters with a mix of uppercase letters, numbers, or symbols.');
            }
        };
    }

    public function index(Request $request)
    {
        $query = User::query();

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->role && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        $users = $query->latest()->paginate($request->per_page ?? 10);
        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->merge(['profile_image' => $request->profile_image ?: null]);

        $request->validate([
            'name'          => 'required|string',
            'email'         => 'required|email|unique:users,email',
            'password'      => ['required', 'min:6', $this->passwordStrengthRule()],
            'role'          => 'required|in:cashier',
            'profile_image' => ['nullable', 'string', 'max:1000000', 'regex:/^data:image\/(png|jpe?g|gif|webp);base64,/'],
        ]);

        $user = User::create([
            'name'          => $request->name,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),
            'role'          => $request->role,
            'profile_image' => $request->profile_image ?? null,
        ]);

        AuditLog::record(Auth::id(), 'user_created', 'User', $user->id, "Created user \"{$user->name}\" ({$user->email})");

        return response()->json(['message' => 'User created!', 'user' => $user]);
    }

    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        if ($user->is_owner && Auth::id() != $user->id) {
            return response()->json([
                'message' => 'The owner account cannot be modified by another admin.',
            ], 403);
        }

        $request->merge(['profile_image' => $request->profile_image ?: null]);

        $request->validate([
            'name'          => 'required|string',
            'email'         => 'required|email|unique:users,email,' . $id,
            'role'          => 'required|in:admin,cashier',
            'password'      => ['nullable', 'min:6', $this->passwordStrengthRule()],
            'profile_image' => ['nullable', 'string', 'max:1000000', 'regex:/^data:image\/(png|jpe?g|gif|webp);base64,/'],
        ]);

        if ($user->role === 'admin' && Auth::id() == $user->id && $request->role !== 'admin') {
            return response()->json([
                'message' => 'You cannot change your own admin role.',
            ], 403);
        }

        if ($user->role === 'admin' && $request->role !== 'admin' && User::where('role', 'admin')->count() <= 1) {
            return response()->json([
                'message' => 'Cannot remove the last remaining admin.',
            ], 403);
        }

        $data = [
            'name'          => $request->name,
            'email'         => $request->email,
            'role'          => $request->role,
            'profile_image' => $request->profile_image ?? $user->profile_image,
        ];

        if ($request->password) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        AuditLog::record(Auth::id(), 'user_updated', 'User', $user->id, "Updated user \"{$user->name}\" ({$user->email})");

        return response()->json(['message' => 'User updated!', 'user' => $user]);
    }

    public function destroy(int $id)
    {
        if (Auth::id() == $id) {
            return response()->json([
                'message' => 'You cannot delete your own active account.',
            ], 403);
        }

        $user = User::findOrFail($id);

        if ($user->is_owner) {
            return response()->json([
                'message' => 'The owner account cannot be deleted.',
            ], 403);
        }

        if ($user->role === 'admin' && User::where('role', 'admin')->count() <= 1) {
            return response()->json([
                'message' => 'Cannot delete the last remaining admin.',
            ], 403);
        }

        $user->delete();

        AuditLog::record(Auth::id(), 'user_deleted', 'User', $id, "Deleted user \"{$user->name}\" ({$user->email})");

        return response()->json(['message' => 'User deleted!']);
    }
}
