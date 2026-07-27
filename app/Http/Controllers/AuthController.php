<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!$token = JWTAuth::attempt($credentials)) {
            AuditLog::record(null, 'login_failed', 'User', null, "Failed login attempt for \"{$credentials['email']}\"");
            return response()->json(['message' => 'Invalid email or password!'], 401);
        }

        $user = JWTAuth::user();
        AuditLog::record($user->id, 'user_logged_in', 'User', $user->id, "\"{$user->name}\" logged in");

        return response()->json([
            'message' => 'Login successful!',
            'token' => $token,
        ]);
    }

    public function logout()
    {
        $user = JWTAuth::user();
        if ($user) {
            AuditLog::record($user->id, 'user_logged_out', 'User', $user->id, "\"{$user->name}\" logged out");
        }

        JWTAuth::invalidate(JWTAuth::getToken());
        return response()->json(['message' => 'Logout successful!']);
    }

    public function me()
    {
        return response()->json(JWTAuth::user());
    }
}