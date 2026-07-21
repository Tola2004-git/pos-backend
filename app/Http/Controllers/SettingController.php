<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SettingController extends Controller
{
    public function getLowStockThreshold()
    {
        return response()->json([
            'threshold' => (int) Setting::get('low_stock_threshold', 10),
        ]);
    }

    public function updateLowStockThreshold(Request $request)
    {
        $request->validate([
            'threshold' => 'required|integer|min:1',
        ]);

        Setting::put('low_stock_threshold', (string) $request->threshold);

        AuditLog::record(Auth::id(), 'setting_updated', 'Setting', null, "Set low_stock_threshold to {$request->threshold}");

        return response()->json(['threshold' => (int) $request->threshold]);
    }
}
