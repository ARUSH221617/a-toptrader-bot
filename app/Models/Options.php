<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Options extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'data',
    ];

    public static function get(string $key, $default = null)
    {
        $option = self::where('key', $key)->first();
        return $option ? $option->data : $default;
    }

    public static function set(string $key, $data): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['data' => $data]
        );
    }
}
