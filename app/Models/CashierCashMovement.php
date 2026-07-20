<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierCashMovement extends Model
{
    protected $fillable = [
        'cashier_shift_id', 'user_id', 'type', 'amount_usd', 'amount_khr', 'reason',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:2',
        'amount_khr' => 'decimal:2',
    ];

    public function shift()
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
