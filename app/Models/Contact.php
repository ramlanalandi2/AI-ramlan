<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'phone',
        'name',
        'fb_profile_url',
        'gender',
        'relation_type',
        'is_verified',
        'identity_locked',
        'verified_by',
        'identity_notes',
        'confidence_score',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'identity_locked' => 'boolean',
    ];

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function memories()
    {
        return $this->hasMany(Memory::class);
    }
}
