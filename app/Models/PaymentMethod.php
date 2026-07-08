<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
        'name',
        'icon',
        'description',
        'logo',
        'bank_name',
        'account_number',
        'account_name',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}