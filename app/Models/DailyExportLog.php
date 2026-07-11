<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyExportLog extends Model
{
    protected $fillable = [
        'export_date', 'file_path', 'orders_count', 'total_amount', 'generated_at',
    ];

    protected $casts = [
        'export_date'  => 'date',
        'total_amount' => 'decimal:2',
        'generated_at' => 'datetime',
    ];
}
