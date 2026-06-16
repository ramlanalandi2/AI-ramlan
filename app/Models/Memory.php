<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    protected $fillable = [
        'contact_id',
        'memory_type',
        'source',
        'source_message_id',
        'meta',
        'content',
        'importance',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
