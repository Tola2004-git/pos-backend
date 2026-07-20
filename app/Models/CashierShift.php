<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierShift extends Model
{
    protected $fillable = [
        'user_id', 'opened_at', 'closed_at',
        'opening_cash_usd', 'opening_cash_khr',
        'expected_cash_usd', 'expected_cash_khr',
        'counted_cash_usd', 'counted_cash_khr',
        'variance_usd', 'variance_khr',
        'note', 'status',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'opened_at'         => 'datetime',
        'closed_at'         => 'datetime',
        'reviewed_at'       => 'datetime',
        'opening_cash_usd'  => 'decimal:2',
        'opening_cash_khr'  => 'decimal:2',
        'expected_cash_usd' => 'decimal:2',
        'expected_cash_khr' => 'decimal:2',
        'counted_cash_usd'  => 'decimal:2',
        'counted_cash_khr'  => 'decimal:2',
        'variance_usd'      => 'decimal:2',
        'variance_khr'      => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function cashMovements()
    {
        return $this->hasMany(CashierCashMovement::class);
    }
}
