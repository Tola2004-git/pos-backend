<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'title', 'category', 'amount_usd', 'amount_khr', 'expense_date', 'note', 'user_id',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount_usd'   => 'decimal:2',
        'amount_khr'   => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
