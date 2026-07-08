<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
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

        $users = $query->latest()->paginate(10);
        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:6',
            'role'     => 'required|in:admin,cashier',
        ]);

        $user = User::create([
            'name'          => $request->name,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),
            'role'          => $request->role,
            'profile_image' => $request->profile_image ?? null,
        ]);

        return response()->json(['message' => 'User created!', 'user' => $user]);
    }

    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name'  => 'required|string',
            'email' => 'required|email|unique:users,email,' . $id,
            'role'  => 'required|in:admin,cashier',
        ]);

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

        return response()->json(['message' => 'User updated!', 'user' => $user]);
    }

    public function destroy(int $id)
    {
        User::findOrFail($id)->delete();
        return response()->json(['message' => 'User deleted!']);
    }
}
