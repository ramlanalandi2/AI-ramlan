<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessService extends Model
{
    protected $fillable = ['name', 'base_price', 'description', 'is_active'];
}
