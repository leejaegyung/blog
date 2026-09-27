<?php

namespace App\Http\Resources;

use App\Enums\ParseStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ReferenceDocument */
class ReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $features = $this->whenLoaded('features', fn () => $this->features);

        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_url' => $this->source_url,
            'title' => $this->title,
            'author' => $this->author,
            'published_at' => $this->published_at,
            'parse_status' => $this->parse_status,
            'error_message' => $this->error_message,
            'char_count' => $features?->char_count,
            'paragraph_count' => $features?->paragraph_count,
            'image_count' => $features?->image_count,
            'heading_count' => $features?->features_json['heading_count'] ?? null,
            'collected_at' => $this->collected_at,
            'created_at' => $this->created_at,
        ];
    }
}
