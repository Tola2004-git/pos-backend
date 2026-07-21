<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BackupLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

class BackupController extends Controller
{
    public function index(Request $request)
    {
        $logs = BackupLog::with('triggeredBy:id,name')
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 15);

        return response()->json($logs);
    }

    // Manually run a backup right now, same command the daily schedule uses.
    public function generate()
    {
        $user = JWTAuth::parseToken()->authenticate();

        Artisan::call('app:backup-database', ['--user_id' => $user->id]);

        $log = BackupLog::latest()->first();

        return response()->json(['message' => 'Backup generated.', 'backup' => $log]);
    }

    public function download(int $id)
    {
        $log = BackupLog::findOrFail($id);
        $disks = $log->disks ?? [];

        if ($log->file_path && in_array('local', $disks, true) && Storage::disk('local')->exists($log->file_path)) {
            return Storage::disk('local')->download($log->file_path, $log->filename);
        }

        if ($log->file_path && in_array('google', $disks, true) && Storage::disk('google')->exists($log->file_path)) {
            return Storage::disk('google')->download($log->file_path, $log->filename);
        }

        return response()->json(['message' => 'Backup file not found on any storage disk.'], 404);
    }

    public function restore(Request $request, int $id)
    {
        $request->validate([
            'confirm' => 'required|in:RESTORE',
        ]);

        $log = BackupLog::findOrFail($id);
        $disks = $log->disks ?? [];

        $sql = null;
        if ($log->file_path && in_array('local', $disks, true) && Storage::disk('local')->exists($log->file_path)) {
            $sql = Storage::disk('local')->get($log->file_path);
        } elseif ($log->file_path && in_array('google', $disks, true) && Storage::disk('google')->exists($log->file_path)) {
            $sql = Storage::disk('google')->get($log->file_path);
        }

        if (! $sql) {
            return response()->json(['message' => 'Backup file not found on any storage disk.'], 404);
        }

        $user = JWTAuth::parseToken()->authenticate();

        try {
            foreach ($this->splitSqlStatements($sql) as $statement) {
                DB::unprepared($statement);
            }
        } catch (\Throwable $e) {
            AuditLog::record($user->id, 'backup_restore_failed', 'BackupLog', $log->id, $e->getMessage());
            return response()->json(['message' => 'Restore failed: ' . $e->getMessage()], 500);
        }

        AuditLog::record($user->id, 'backup_restored', 'BackupLog', $log->id, "Restored database from {$log->filename}");

        return response()->json(['message' => "Database restored from {$log->filename}."]);
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $current .= $char;

            if ($inString !== null) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i];
                    continue;
                }
                if ($char === $inString) {
                    $inString = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString = $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($current);
                if ($statement !== '' && $statement !== ';') {
                    $statements[] = $statement;
                }
                $current = '';
            }
        }

        $trailing = trim($current);
        if ($trailing !== '') {
            $statements[] = $trailing;
        }

        return $statements;
    }
}
