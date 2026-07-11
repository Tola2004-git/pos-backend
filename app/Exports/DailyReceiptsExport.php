<?php

namespace App\Exports;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class DailyReceiptsExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithStyles,
    WithColumnFormatting,
    WithEvents,
    WithTitle,
    ShouldAutoSize
{
    private const LAST_COLUMN = 'M';

    public function __construct(private readonly Carbon $date)
    {
    }

    public function query(): Builder
    {
        return Order::query()
            ->whereDate('created_at', $this->date)
            ->with(['items', 'paymentMethod'])
            ->orderBy('created_at');
    }

    public function headings(): array
    {
        return [
            'Order #',
            'Date & Time',
            'Customer',
            'Items',
            'Subtotal (USD)',
            'Discount (USD)',
            'Total (USD)',
            'Payment Method',
            'Paid (USD)',
            'Paid (KHR)',
            'Change (USD)',
            'Status',
            'Note',
        ];
    }

    public function map($order): array
    {
        $items = $order->items
            ->map(fn ($item) => "{$item->product_name} x{$item->quantity}")
            ->implode(', ');

        return [
            $order->order_number,
            $order->created_at?->format('Y-m-d H:i:s'),
            $order->customer_name ?: '-',
            $items,
            (float) $order->subtotal,
            (float) $order->discount,
            (float) $order->total,
            $order->paymentMethod?->name ?: '-',
            (float) $order->amount_paid_usd,
            (float) $order->amount_paid_khr,
            (float) $order->change_amount,
            ucfirst($order->status),
            $order->note ?: '',
        ];
    }

    public function title(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function columnFormats(): array
    {
        return [
            'E' => '"$"#,##0.00',
            'F' => '"$"#,##0.00',
            'G' => '"$"#,##0.00',
            'I' => '"$"#,##0.00',
            'J' => '#,##0" ៛"',
            'K' => '"$"#,##0.00',
        ];
    }

    public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2F5233'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastCol = self::LAST_COLUMN;

                $sheet->getRowDimension(1)->setRowHeight(22);
                $sheet->freezePane('A2');

                $sheet->getStyle("A1:{$lastCol}{$lastRow}")->getBorders()
                    ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                $sheet->getStyle("E2:G{$lastRow}")
                    ->getNumberFormat()->setFormatCode('"$"#,##0.00');
                $sheet->getStyle("G2:G{$lastRow}")
                    ->getFont()->setBold(true);

                if ($lastRow >= 2) {
                    $totalRow = $lastRow + 2;
                    $sheet->setCellValue("F{$totalRow}", 'GRAND TOTAL');
                    $sheet->setCellValue("G{$totalRow}", "=SUM(G2:G{$lastRow})");
                    $sheet->getStyle("F{$totalRow}:G{$totalRow}")->getFont()->setBold(true);
                    $sheet->getStyle("G{$totalRow}")->getNumberFormat()->setFormatCode('"$"#,##0.00');
                    $sheet->getStyle("F{$totalRow}:G{$totalRow}")->getBorders()
                        ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                }

                $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setWrapText(true);
            },
        ];
    }
}
