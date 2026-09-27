<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KeywordProject extends Model
{
    /** @use HasFactory<\Database\Factories\KeywordProjectFactory> */
    use HasFactory;

    protected $fillable = ['keyword', 'category'];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'last_analyzed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(ReferenceDocument::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(KeywordAnalysis::class);
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(KeywordAnalysis::class)->latestOfMany();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
