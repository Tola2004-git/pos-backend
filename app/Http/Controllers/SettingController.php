<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

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

        return response()->json(['threshold' => (int) $request->threshold]);
    }
}
