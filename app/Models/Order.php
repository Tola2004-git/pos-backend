<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'user_id', 'table_id', 'table_name', 'customer_name', 'customer_phone',
        'pager_number', 'order_type',
        'subtotal', 'discount', 'tax', 'total',
        'payment_method_id', 'promotion_id', 'amount_paid', 'change_amount',
        'amount_paid_usd', 'amount_paid_khr', 'exchange_rate_used',
        'status', 'note'
    ];

    protected $casts = [
        'subtotal'           => 'decimal:2',
        'discount'           => 'decimal:2',
        'tax'                => 'decimal:2',
        'total'              => 'decimal:2',
        'amount_paid'        => 'decimal:2',
        'change_amount'      => 'decimal:2',
        'amount_paid_usd'    => 'decimal:2',
        'amount_paid_khr'    => 'decimal:2',
        'exchange_rate_used' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }
}