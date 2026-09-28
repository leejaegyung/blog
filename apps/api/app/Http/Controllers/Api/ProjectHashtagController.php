<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\KeywordProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** 키워드별 해시태그 고치기. null을 보내면 최근 분석의 추천으로 되돌린다. */
class ProjectHashtagController extends Controller
{
    public function __invoke(Request $request, KeywordProject $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'hashtags' => ['present', 'nullable', 'array', 'max:30'],
            'hashtags.*' => ['nullable', 'string', 'max:40'],
        ], [
            'hashtags.max' => '해시태그는 30개까지 달 수 있어요.',
        ]);

        $project->forceFill([
            'hashtags_json' => $data['hashtags'] === null ? null : KeywordProject::normalizeHashtags($data['hashtags']),
        ])->save();

        return new ProjectResource($project->loadCount(['references', 'posts']));
    }
}
