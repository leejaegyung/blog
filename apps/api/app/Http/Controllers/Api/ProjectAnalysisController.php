<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\KeywordAnalysisResource;
use App\Jobs\AnalyzeKeywordJob;
use App\Models\KeywordProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectAnalysisController extends Controller
{
    public function show(KeywordProject $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $analysis = $project->latestAnalysis;

        return response()->json([
            'data' => $analysis ? new KeywordAnalysisResource($analysis) : null,
            'status' => $project->status,
        ]);
    }

    public function analyze(Request $request, KeywordProject $project): JsonResponse
    {
        Gate::authorize('update', $project);

        if ($project->status === ProjectStatus::Analyzing) {
            return response()->json(['status' => $project->status, 'cached' => false], 202);
        }

        // 참고자료·키워드가 그대로이고 유효기간 안이며 AI 해석까지 있으면 다시 호출하지 않는다(기획서 21장).
        $latest = $project->latestAnalysis;
        $reusable = $latest
            && ! $request->boolean('force')
            && $latest->insight_json !== null
            && $latest->expires_at?->isFuture()
            && $latest->source_hash === AnalyzeKeywordJob::sourceHash($project);

        if ($reusable) {
            return response()->json([
                'data' => new KeywordAnalysisResource($latest),
                'status' => $project->status,
                'cached' => true,
            ]);
        }

        $project->forceFill(['status' => ProjectStatus::Analyzing])->save();
        AnalyzeKeywordJob::dispatch($project);

        return response()->json(['status' => $project->status, 'cached' => false], 202);
    }
}
