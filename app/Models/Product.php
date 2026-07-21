<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name', 'category_id', 'price',
        'sku', 'barcode', 'qty', 'image', 'status'
    ];

    protected $casts = [
        'status' => 'boolean',
        'price'  => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function ingredients()
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredients')
            ->withPivot('quantity')
            ->withTimestamps();
    }
}