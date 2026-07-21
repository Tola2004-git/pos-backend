<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $table = 'bakery_ingredients';

    protected $fillable = [
        'name', 'category_id', 'unit', 'quantity',
        'low_stock_threshold', 'cost_per_unit', 'expiry_date',
        'supplier', 'status', 'note'
    ];

    protected $casts = [
        'status'               => 'boolean',
        'quantity'             => 'decimal:2',
        'low_stock_threshold'  => 'decimal:2',
        'cost_per_unit'        => 'decimal:2',
        'expiry_date'          => 'date',
    ];

    protected $appends = ['is_expired', 'is_expiring_soon'];
    private const EXPIRING_SOON_DAYS = 3;

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        if ($this->expiry_date === null || $this->is_expired) {
            return false;
        }

        return $this->expiry_date->lte(now()->addDays(self::EXPIRING_SOON_DAYS));
    }

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
