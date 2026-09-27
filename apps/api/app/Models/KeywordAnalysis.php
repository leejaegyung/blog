<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordAnalysis extends Model
{
    protected $fillable = [
        'primary_intent', 'related_keywords_json', 'common_topics_json',
        'recommended_outline_json', 'title_patterns_json', 'must_answer_json',
        'stats_json', 'insight_json', 'insight_error', 'prompt_version',
        'source_hash', 'analyzer_version', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'related_keywords_json' => 'array',
            'common_topics_json' => 'array',
            'recommended_outline_json' => 'array',
            'title_patterns_json' => 'array',
            'must_answer_json' => 'array',
            'stats_json' => 'array',
            'insight_json' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(KeywordProject::class, 'keyword_project_id');
    }
}
