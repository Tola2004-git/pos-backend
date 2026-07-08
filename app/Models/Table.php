<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    protected $fillable = ['name', 'capacity', 'status', 'notes'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function currentOrder()
    {
        return $this->hasOne(Order::class)->whereIn('status', ['pending', 'completed'])->latest();
    }
}
