<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender',
        'message_text',
        'wa_message_id',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
