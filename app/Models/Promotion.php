<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;
use App\Models\Category;

class Promotion extends Model
{
    protected $fillable = [
        'name',
        'type',
        'value',
        'apply_to',
        'min_purchase',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'value'        => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'start_date'   => 'date',
        'end_date'     => 'date',
        'status'       => 'boolean',
    ];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'promotion_categories');
    }
}