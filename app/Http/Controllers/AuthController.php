<?php

namespace App\Http\Controllers;

use App\Mail\SuspiciousLoginAlert;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    private const SUSPICIOUS_LOGIN_THRESHOLD = 5;
    private const SUSPICIOUS_LOGIN_WINDOW_HOURS = 1;

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!$token = JWTAuth::attempt($credentials)) {
            AuditLog::record(null, 'login_failed', 'User', null, "Failed login attempt for \"{$credentials['email']}\"");
            $this->maybeNotifyAdminsOfSuspiciousLogins();
            return response()->json(['message' => 'Invalid email or password!'], 401);
        }

        $user = JWTAuth::user();
        AuditLog::record($user->id, 'user_logged_in', 'User', $user->id, "\"{$user->name}\" logged in");

        return response()->json([
            'message' => 'Login successful!',
            'token' => $token,
        ]);
    }

    // Called automatically by the frontend's axios response interceptor the
    // moment an API call comes back 401 for an expired (but not yet past
    // refresh_ttl) token - trades a short-lived access token (harder to
    // abuse if leaked) for the UX of a long-lived one, without needing to
    // just extend JWT_TTL and accept a stolen token staying valid for hours.
    // Deliberately outside the auth:api group: that guard rejects an expired
    // token outright, but refreshing one expired-but-still-in-window is
    // exactly the point of this endpoint.
    public function refresh()
    {
        try {
            $newToken = JWTAuth::parseToken()->refresh();
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['message' => 'Session expired. Please log in again.'], 401);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not refresh session.'], 401);
        }

        return response()->json(['token' => $newToken]);
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

    // Cache-gated so a sustained attack sends one email per window instead
    // of one per failed attempt - without this, a real brute-force run would
    // flood every admin's inbox instead of just telling them once.
    private function maybeNotifyAdminsOfSuspiciousLogins(): void
    {
        $failedLoginCount = AuditLog::where('action', 'login_failed')
            ->where('created_at', '>=', now()->subHours(self::SUSPICIOUS_LOGIN_WINDOW_HOURS))
            ->count();

        if ($failedLoginCount < self::SUSPICIOUS_LOGIN_THRESHOLD) {
            return;
        }

        if (! Cache::add('suspicious_login_alert_sent', true, now()->addHours(self::SUSPICIOUS_LOGIN_WINDOW_HOURS))) {
            return;
        }

        $adminEmails = User::where('role', 'admin')->pluck('email');

        try {
            Mail::to($adminEmails)->send(new SuspiciousLoginAlert($failedLoginCount, self::SUSPICIOUS_LOGIN_WINDOW_HOURS));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}