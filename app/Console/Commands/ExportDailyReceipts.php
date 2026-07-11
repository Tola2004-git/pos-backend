<?php

namespace App\Console\Commands;

use App\Exports\DailyReceiptsExport;
use App\Models\DailyExportLog;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class ExportDailyReceipts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:export-daily-receipts {date? : Date to export (Y-m-d), defaults to today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export the day\'s receipts/orders into a formatted Excel file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : Carbon::today();

        $ordersQuery = Order::query()
            ->whereDate('created_at', $date)
            ->where('status', 'completed');
        $ordersCount = $ordersQuery->count();
        $totalAmount = (clone $ordersQuery)->sum('total');

        $fileName = "receipts-{$date->format('Y-m-d')}.xlsx";

        Excel::store(new DailyReceiptsExport($date), $fileName, 'google');

        DailyExportLog::updateOrCreate(
            ['export_date' => $date->toDateString()],
            [
                'file_path'    => $fileName,
                'orders_count' => $ordersCount,
                'total_amount' => $totalAmount,
                'generated_at' => now(),
            ]
        );

        $this->info("Exported {$ordersCount} order(s) for {$date->toDateString()} to Google Drive as {$fileName}");

        return self::SUCCESS;
    }
}
