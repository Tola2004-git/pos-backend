<?php

namespace App\Http\Controllers;

use App\Models\DailyExportLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class DailyExportController extends Controller
{
    // List generated daily receipt exports (newest first)
    public function index(Request $request)
    {
        $logs = DailyExportLog::query()
            ->orderByDesc('export_date')
            ->paginate($request->per_page ?? 15);

        return response()->json($logs);
    }

    // Download the Excel file for a given date (Y-m-d)
    public function download(string $date)
    {
        $log = DailyExportLog::where('export_date', Carbon::parse($date)->toDateString())->first();

        if (!$log || !Storage::disk('google')->exists($log->file_path)) {
            return response()->json(['message' => 'No export found for this date.'], 404);
        }

        return Storage::disk('google')->download($log->file_path, basename($log->file_path));
    }

    // Manually (re)generate the export for a given date, defaults to today
    public function generate(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $request->date ? Carbon::parse($request->date) : Carbon::today();

        Artisan::call('app:export-daily-receipts', ['date' => $date->toDateString()]);

        $log = DailyExportLog::where('export_date', $date->toDateString())->first();

        return response()->json([
            'message' => 'Export generated.',
            'export'  => $log,
        ]);
    }
}
