<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DailyExportLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DailyExportController extends Controller
{
    public function index(Request $request)
    {
        $logs = DailyExportLog::query()
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 15);

        return response()->json($logs);
    }

    public function download(int $id)
    {
        $log = DailyExportLog::find($id);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('google');

        if (!$log || !$disk->exists($log->file_path)) {
            return response()->json(['message' => 'No export found for this date.'], 404);
        }

        return $disk->download($log->file_path, basename($log->file_path));
    }

    public function destroy(int $id)
    {
        $log = DailyExportLog::find($id);

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
                'id'    => $log->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to delete the file from Google Drive. Check the connection and try again.',
            ], 500);
        }

        $exportRange = $log->date_from->toDateString() . ' to ' . $log->date_to->toDateString();
        $log->delete();

        AuditLog::record(Auth::id(), 'daily_export_deleted', 'DailyExportLog', null, "Deleted export for {$exportRange}");

        return response()->json(['message' => 'Export deleted!']);
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['required_with:date_to', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $defaultDate = $validated['date'] ?? Carbon::today()->toDateString();
        $dateFrom = Carbon::parse($validated['date_from'] ?? $defaultDate)->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'] ?? $validated['date_from'] ?? $defaultDate)->endOfDay();

        if ($dateFrom->diffInDays($dateTo) + 1 > 366) {
            return response()->json(['message' => 'The selected date range cannot exceed 366 days.'], 422);
        }

        // The export command uploads to Google Drive (config/filesystems.php
        // 'google' disk) - if those credentials are missing, expired, or
        // revoked, the upload throws and would otherwise surface as a raw
        // 500 with no indication of what actually went wrong.
        try {
            Artisan::call('app:export-daily-receipts', [
                'date' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Daily export generation failed', [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate the export. Check that Google Drive is connected and try again.',
            ], 500);
        }

        $log = DailyExportLog::whereDate('date_from', $dateFrom->toDateString())
            ->whereDate('date_to', $dateTo->toDateString())
            ->first();

        AuditLog::record(Auth::id(), 'daily_export_generated', 'DailyExportLog', $log?->id, "Generated export from {$dateFrom->toDateString()} to {$dateTo->toDateString()}");

        return response()->json([
            'message' => 'Export generated.',
            'export'  => $log,
        ]);
    }
}
