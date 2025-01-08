<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DynamicButton extends Model
{
    /** @use HasFactory<\Database\Factories\DynamicButtonFactory> */
    use HasFactory;

    protected $fillable = [
        'value',
        'slug',
        'dynamic_content_id',
        'status',
    ];

    public function dynamicContent()
    {
        return $this->belongsTo(DynamicContent::class);
    }
}
