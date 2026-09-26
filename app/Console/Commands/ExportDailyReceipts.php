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
    protected $signature = 'app:export-daily-receipts {date? : Start date to export (Y-m-d), defaults to today} {date_to? : End date to export (Y-m-d), defaults to the start date}';

    protected $description = 'Export the day\'s receipts/orders into a formatted Excel file';

    public function handle(): int
    {
        $dateFrom = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : Carbon::today();
        $dateTo = $this->argument('date_to')
            ? Carbon::parse($this->argument('date_to'))
            : $dateFrom->copy();
        $dateFrom->startOfDay();
        $dateTo->endOfDay();

        $ordersQuery = Order::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'completed');
        $ordersCount = $ordersQuery->count();
        $totalAmount = (clone $ordersQuery)->sum('total');

        $dateLabel = $dateFrom->isSameDay($dateTo)
            ? $dateFrom->format('Y-m-d')
            : $dateFrom->format('Y-m-d') . '-to-' . $dateTo->format('Y-m-d');
        $fileName = "receipts-{$dateLabel}.xlsx";

        Excel::store(new DailyReceiptsExport($dateFrom, $dateTo), $fileName, 'google');

        DailyExportLog::updateOrCreate(
            [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            [
                'export_date' => $dateFrom->toDateString(),
                'file_path'    => $fileName,
                'orders_count' => $ordersCount,
                'total_amount' => $totalAmount,
                'generated_at' => now(),
            ]
        );

        $this->info("Exported {$ordersCount} order(s) from {$dateFrom->toDateString()} to {$dateTo->toDateString()} to Google Drive as {$fileName}");

        return self::SUCCESS;
    }
}
