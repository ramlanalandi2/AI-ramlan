<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProduct extends Model
{
    protected $fillable = ['name', 'price', 'stock', 'description', 'is_active'];
}
