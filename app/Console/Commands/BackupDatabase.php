<?php

namespace App\Console\Commands;

use App\Models\BackupLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backup-database {--user_id= : ID of the admin who triggered this manually}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dump the database to a .sql file and store it on the local and Google Drive disks';

    public function handle(): int
    {
        $timestamp = Carbon::now();
        $fileName = 'backup-' . $timestamp->format('Y-m-d_His') . '.sql';
        $relativePath = 'backups/' . $fileName;
        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;

        $log = BackupLog::create([
            'filename'     => $fileName,
            'type'         => $userId ? 'manual' : 'scheduled',
            'status'       => 'running',
            'triggered_by' => $userId,
        ]);

        $localDisk = Storage::disk('local');
        $absolutePath = $localDisk->path($relativePath);

        try {
            if (! is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0755, true);
            }

            $tablesCount = $this->writeDump($absolutePath, $timestamp);

            $sizeBytes = filesize($absolutePath);
            $disksStored = ['local'];
            $googleError = null;

            try {
                $stream = fopen($absolutePath, 'r');
                Storage::disk('google')->put($relativePath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $disksStored[] = 'google';
            } catch (\Throwable $e) {
                $googleError = $e->getMessage();
                \Illuminate\Support\Facades\Log::warning("Backup Google Drive upload failed: {$googleError}");
                $this->warn("Google Drive upload failed, keeping local copy only: {$googleError}");
            }

            $log->update([
                'status'        => 'success',
                'file_path'     => $relativePath,
                'disks'         => $disksStored,
                'size_bytes'    => $sizeBytes,
                'tables_count'  => $tablesCount,
                'error_message' => $googleError,
                'completed_at'  => now(),
            ]);

            $this->info("Backup complete: {$fileName} ({$sizeBytes} bytes) stored on: " . implode(', ', $disksStored));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $this->error("Backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function writeDump(string $absolutePath, Carbon $timestamp): int
    {
        $handle = fopen($absolutePath, 'w');
        if (! $handle) {
            throw new \RuntimeException("Could not open {$absolutePath} for writing.");
        }

        fwrite($handle, "-- Database backup generated {$timestamp->toDateTimeString()}\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $pdo = DB::connection()->getPdo();
        $tables = array_map(
            fn ($row) => array_values((array) $row)[0],
            DB::select('SHOW TABLES')
        );

        foreach ($tables as $table) {
            $createRow = DB::select("SHOW CREATE TABLE `{$table}`")[0];
            $createSql = $createRow->{'Create Table'};

            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n");
            $batchSize = 500;
            $offset = 0;
            $total = DB::table($table)->count();
            while ($offset < $total) {
                $rows = DB::table($table)->offset($offset)->limit($batchSize)->get();
                $this->writeInsertBatch($handle, $table, $rows, $pdo);
                $offset += $batchSize;
            }

            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return count($tables);
    }

    private const MAX_STATEMENT_BYTES = 512 * 1024;

    private function writeInsertBatch($handle, string $table, \Illuminate\Support\Collection $rows, \PDO $pdo): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $columns = array_keys((array) $rows->first());
        $columnList = implode('`, `', $columns);
        $prefix = "INSERT INTO `{$table}` (`{$columnList}`) VALUES\n";

        $valuesList = [];
        $pendingBytes = strlen($prefix);

        $flush = function () use ($handle, &$valuesList, $prefix) {
            if (empty($valuesList)) {
                return;
            }
            fwrite($handle, $prefix . implode(",\n", $valuesList) . ";\n");
            $valuesList = [];
        };

        foreach ($rows as $row) {
            $values = array_map(function ($value) use ($pdo) {
                if ($value === null) {
                    return 'NULL';
                }
                return $pdo->quote((string) $value);
            }, (array) $row);
            $tuple = '(' . implode(', ', $values) . ')';

            if ($valuesList && $pendingBytes + strlen($tuple) > self::MAX_STATEMENT_BYTES) {
                $flush();
                $pendingBytes = strlen($prefix);
            }

            $valuesList[] = $tuple;
            $pendingBytes += strlen($tuple) + 2; // ",\n"
        }

        $flush();
    }
}
