<?php

namespace App\Http\Controllers\Api;

use App\Enums\ParseStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\KeywordAnalysisResource;
use App\Jobs\AnalyzeKeywordJob;
use App\Models\KeywordProject;
use App\Models\ReferenceDocument;
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
            'progress' => $this->progress($project),
        ]);
    }

    public function analyze(Request $request, KeywordProject $project): JsonResponse
    {
        Gate::authorize('update', $project);

        if ($project->status === ProjectStatus::Analyzing) {
            return response()->json(['status' => $project->status, 'progress' => $this->progress($project), 'cached' => false], 202);
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

        $project->markAnalysisQueued();
        AnalyzeKeywordJob::dispatch($project);

        return response()->json(['status' => $project->status, 'progress' => $this->progress($project), 'cached' => false], 202);
    }

    /**
     * 학습 진행 상황: 단계(queued → ai), 시작 시각, 학습할 글 읽기(파싱) 현황.
     *
     * @return array{step: ?string, started_at: ?string, references: array{total: int, parsed: int, pending: int}}
     */
    private function progress(KeywordProject $project): array
    {
        $counts = ReferenceDocument::whereIn('keyword_project_id', AnalyzeKeywordJob::sourceProjectIds($project))
            ->selectRaw('parse_status, count(*) as n')
            ->groupBy('parse_status')
            ->pluck('n', 'parse_status');

        return [
            'step' => $project->status === ProjectStatus::Analyzing ? ($project->analysis_step ?? 'queued') : null,
            'started_at' => $project->status === ProjectStatus::Analyzing ? $project->analysis_started_at?->toIso8601String() : null,
            'references' => [
                'total' => (int) $counts->sum(),
                'parsed' => (int) ($counts[ParseStatus::Parsed->value] ?? 0),
                'pending' => (int) ($counts[ParseStatus::Pending->value] ?? 0),
            ],
        ];
    }
}
