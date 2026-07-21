<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    protected $fillable = [
        'filename', 'file_path', 'type', 'status', 'disks',
        'size_bytes', 'tables_count', 'triggered_by', 'error_message', 'completed_at',
    ];

    protected $casts = [
        'disks'        => 'array',
        'completed_at' => 'datetime',
    ];

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
