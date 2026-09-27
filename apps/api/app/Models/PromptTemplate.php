<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptTemplate extends Model
{
    protected $fillable = [
        'name', 'version', 'system_prompt', 'user_template',
        'provider', 'model', 'temperature', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'enabled' => 'boolean',
        ];
    }
}
