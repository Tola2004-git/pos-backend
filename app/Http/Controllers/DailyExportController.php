<?php

namespace App\Http\Controllers;

use App\Models\DailyExportLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DailyExportController extends Controller
{
    public function index(Request $request)
    {
        $logs = DailyExportLog::query()
            ->orderByDesc('export_date')
            ->paginate($request->per_page ?? 15);

        return response()->json($logs);
    }

    public function download(string $date)
    {
        $log = DailyExportLog::where('export_date', Carbon::parse($date)->toDateString())->first();

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('google');

        if (!$log || !$disk->exists($log->file_path)) {
            return response()->json(['message' => 'No export found for this date.'], 404);
        }

        return $disk->download($log->file_path, basename($log->file_path));
    }

    public function destroy(string $date)
    {
        $log = DailyExportLog::where('export_date', Carbon::parse($date)->toDateString())->first();

        if (!$log) {
            return response()->json(['message' => 'No export found for this date.'], 404);
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('google');

        try {
            if ($disk->exists($log->file_path)) {
                $disk->delete($log->file_path);
            }
        } catch (\Throwable $e) {
            Log::error('Daily export file deletion failed', [
                'date'  => $log->export_date->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to delete the file from Google Drive. Check the connection and try again.',
            ], 500);
        }

        $log->delete();

        return response()->json(['message' => 'Export deleted!']);
    }

    public function generate(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $request->date ? Carbon::parse($request->date) : Carbon::today();

        // The export command uploads to Google Drive (config/filesystems.php
        // 'google' disk) - if those credentials are missing, expired, or
        // revoked, the upload throws and would otherwise surface as a raw
        // 500 with no indication of what actually went wrong.
        try {
            Artisan::call('app:export-daily-receipts', ['date' => $date->toDateString()]);
        } catch (\Throwable $e) {
            Log::error('Daily export generation failed', [
                'date'  => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate the export. Check that Google Drive is connected and try again.',
            ], 500);
        }

        $log = DailyExportLog::where('export_date', $date->toDateString())->first();

        return response()->json([
            'message' => 'Export generated.',
            'export'  => $log,
        ]);
    }
}
