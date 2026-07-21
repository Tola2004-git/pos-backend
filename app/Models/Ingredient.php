<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $table = 'bakery_ingredients';

    protected $fillable = [
        'name', 'category_id', 'unit', 'quantity',
        'low_stock_threshold', 'cost_per_unit',
        'supplier', 'status', 'note'
    ];

    protected $casts = [
        'status'               => 'boolean',
        'quantity'             => 'decimal:2',
        'low_stock_threshold'  => 'decimal:2',
        'cost_per_unit'        => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function stockLogs()
    {
        return $this->hasMany(IngredientStockLog::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_ingredients')
            ->withPivot('quantity')
            ->withTimestamps();
    }
}
