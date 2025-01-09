<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TgSession extends Model
{
    /** @use HasFactory<\Database\Factories\SessionsFactory> */
    use HasFactory;

    protected $table = 'tg_sessions';

    protected $fillable = [
        'key',
        'value',
        'user_id',
        'chat_id'
    ];

    /**
     * Get the user that owns the session.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
