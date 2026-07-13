<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngredientStockLog extends Model
{
    protected $fillable = [
        'ingredient_id', 'user_id', 'action',
        'quantity', 'qty_before', 'qty_after',
        'supplier', 'note'
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
