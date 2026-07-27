<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BackupLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
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

        AuditLog::record($user->id, 'backup_generated', 'BackupLog', $log->id, "Manually generated backup \"{$log->filename}\" (status: {$log->status}).");

        return response()->json(['message' => 'Backup generated.', 'backup' => $log]);
    }

    public function download(int $id)
    {
        $log = BackupLog::findOrFail($id);
        $user = JWTAuth::parseToken()->authenticate();

        try {
            $content = $this->readBackupSql($log);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not decrypt backup file: ' . $e->getMessage()], 500);
        }

        if ($content === null) {
            return response()->json(['message' => 'Backup file not found on any storage disk.'], 404);
        }

        AuditLog::record($user->id, 'backup_downloaded', 'BackupLog', $log->id, "Downloaded backup \"{$log->filename}\".");

        $downloadName = preg_replace('/\.enc$/', '', $log->filename);

        return response($content, 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
        ]);
    }

    public function restore(Request $request, int $id)
    {
        $request->validate([
            'confirm' => 'required|in:RESTORE',
        ]);

        $log = BackupLog::findOrFail($id);

        try {
            $sql = $this->readBackupSql($log);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not decrypt backup file: ' . $e->getMessage()], 500);
        }

        if ($sql === null) {
            return response()->json(['message' => 'Backup file not found on any storage disk.'], 404);
        }

        $user = JWTAuth::parseToken()->authenticate();

        // Snapshot the current database before overwriting it, so a mistaken
        // restore can itself be undone by restoring this safety backup. If we
        // can't take the snapshot, refuse to proceed rather than risk losing
        // data with no way back.
        Artisan::call('app:backup-database', ['--user_id' => $user->id]);
        $safetyLog = BackupLog::latest()->first();

        if (! $safetyLog || $safetyLog->status !== 'success') {
            return response()->json([
                'message' => 'Restore aborted: could not create a safety backup of the current database first.',
            ], 500);
        }

        try {
            foreach ($this->splitSqlStatements($sql) as $statement) {
                DB::unprepared($statement);
            }
        } catch (\Throwable $e) {
            AuditLog::record($user->id, 'backup_restore_failed', 'BackupLog', $log->id, $e->getMessage() . " (safety backup: \"{$safetyLog->filename}\")");
            return response()->json(['message' => 'Restore failed: ' . $e->getMessage() . ". A safety backup of the pre-restore state was saved as \"{$safetyLog->filename}\"."], 500);
        } finally {
            // The dump's own "SET FOREIGN_KEY_CHECKS=1;" is the last statement
            // in the file, so it never runs if an earlier statement throws.
            // Re-enable it unconditionally so a failed restore doesn't leave
            // referential integrity silently disabled for this connection.
            DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
        }

        AuditLog::record($user->id, 'backup_restored', 'BackupLog', $log->id, "Restored database from \"{$log->filename}\". Safety backup: \"{$safetyLog->filename}\".");

        return response()->json(['message' => "Database restored from {$log->filename}."]);
    }

    public function destroy(int $id)
    {
        $log = BackupLog::findOrFail($id);
        $disks = $log->disks ?? [];

        if ($log->file_path && in_array('local', $disks, true)) {
            Storage::disk('local')->delete($log->file_path);
        }
        if ($log->file_path && in_array('google', $disks, true)) {
            Storage::disk('google')->delete($log->file_path);
        }

        $user = JWTAuth::parseToken()->authenticate();
        $filename = $log->filename;
        $log->delete();

        AuditLog::record($user->id, 'backup_deleted', 'BackupLog', $id, "Deleted backup \"{$filename}\".");

        return response()->json(['message' => 'Backup deleted.']);
    }

    // Reads a backup's SQL off whichever disk still has it, decrypting it if
    // it was stored as a .sql.enc file (older, pre-encryption backups are
    // plain .sql and pass through unchanged). Returns null if the file isn't
    // on any disk; lets Crypt's DecryptException bubble up to the caller.
    private function readBackupSql(BackupLog $log): ?string
    {
        $disks = $log->disks ?? [];
        $content = null;

        if ($log->file_path && in_array('local', $disks, true) && Storage::disk('local')->exists($log->file_path)) {
            $content = Storage::disk('local')->get($log->file_path);
        } elseif ($log->file_path && in_array('google', $disks, true) && Storage::disk('google')->exists($log->file_path)) {
            $content = Storage::disk('google')->get($log->file_path);
        }

        if ($content === null) {
            return null;
        }

        if (str_ends_with($log->file_path, '.enc')) {
            $content = Crypt::decryptString($content);
        }

        return $content;
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = null; // ', ", or `
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($inString !== null) {
                $current .= $char;
                if ($char === '\\' && $inString !== '`' && $i + 1 < $length) {
                    $current .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($char === $inString) {
                    $inString = null;
                }
                $i++;
                continue;
            }

            // Line comment: "-- " or "#" up to the next newline.
            if (($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Block comment: /* ... */
            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $length && ! ($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $inString = $char;
                $current .= $char;
                $i++;
                continue;
            }

            if ($char === ';') {
                $statement = trim($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $trailing = trim($current);
        if ($trailing !== '') {
            $statements[] = $trailing;
        }

        return $statements;
    }
}
