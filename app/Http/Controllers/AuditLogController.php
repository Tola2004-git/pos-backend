<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::with('user:id,name')->latest();

        if ($request->action && $request->action !== 'all') {
            $query->where('action', $request->action);
        }

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    // Powers the Dashboard's security banner - a quick "should I go look at
    // the audit log?" signal without admins needing to remember to check it.
    public function securitySummary()
    {
        $windowHours = 1;
        $since = now()->subHours($windowHours);

        $failedLoginCount = AuditLog::where('action', 'login_failed')
            ->where('created_at', '>=', $since)
            ->count();

        $recentFailedLogins = AuditLog::where('action', 'login_failed')
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(5)
            ->get(['description', 'created_at']);

        return response()->json([
            'window_hours' => $windowHours,
            'failed_login_count' => $failedLoginCount,
            'recent_failed_logins' => $recentFailedLogins,
        ]);
    }
}
