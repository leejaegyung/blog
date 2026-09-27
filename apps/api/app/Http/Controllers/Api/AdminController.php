<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** 1인용 관리 화면(기획서 22장 축소판). 로그인한 사용자 = 관리자. 금액은 계산하지 않고 토큰만 보여준다. */
class AdminController extends Controller
{
    public function usage(Request $request): JsonResponse
    {
        $days = min(max($request->integer('days', 30), 1), 365);
        $since = now()->subDays($days);
        $user = $request->user();

        // 이 사용자의 글·프로젝트에 속한 호출만 (연결 확인 ping 등 소속 없는 호출도 포함)
        $scoped = Generation::query()->where('created_at', '>=', $since)->where(function ($q) use ($user) {
            $q->whereIn('post_id', $user->posts()->select('id'))
                ->orWhereIn('keyword_project_id', $user->keywordProjects()->select('id'))
                ->orWhere(fn ($q) => $q->whereNull('post_id')->whereNull('keyword_project_id'));
        });

        $byGroup = (clone $scoped)
            ->selectRaw("purpose, provider, model, count(*) as calls, sum(case when status = 'success' then 1 else 0 end) as success,
                sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens,
                cast(avg(case when status = 'success' then latency_ms end) as integer) as avg_latency_ms")
            ->groupBy('purpose', 'provider', 'model')
            ->orderByDesc('calls')
            ->get();

        $daily = (clone $scoped)
            ->selectRaw("date(created_at) as day, count(*) as calls, sum(case when status = 'success' then 0 else 1 end) as failed,
                sum(input_tokens + output_tokens) as tokens")
            ->groupBy('day')->orderBy('day')->get();

        $posts = $user->posts();
        $calls = (int) $byGroup->sum('calls');

        return response()->json(['data' => [
            'days' => $days,
            'kpi' => [
                'posts' => (clone $posts)->where('created_at', '>=', $since)->count(),
                'drafted' => (clone $posts)->where('created_at', '>=', $since)->whereNotNull('content_original_json')->count(),
                'published' => (clone $posts)->where('published_at', '>=', $since)->count(),
                'llm_calls' => $calls,
                'llm_failure_rate' => $calls ? round(1 - $byGroup->sum('success') / $calls, 3) : 0,
                'tokens' => (int) ($byGroup->sum('input_tokens') + $byGroup->sum('output_tokens')),
                'avg_draft_latency_ms' => (int) (clone $scoped)->where('purpose', 'draft')->where('status', 'success')->avg('latency_ms'),
            ],
            'by_group' => $byGroup,
            'daily' => $daily,
        ]]);
    }

    public function failedJobs(): JsonResponse
    {
        $jobs = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get()->map(fn ($job) => [
            'uuid' => $job->uuid,
            'job' => json_decode($job->payload, true)['displayName'] ?? 'unknown',
            'queue' => $job->queue,
            // 첫 줄만: 스택 트레이스·페이로드에는 사용자 입력이 섞일 수 있다
            'error' => mb_strimwidth(strtok($job->exception, "\n") ?: '', 0, 300, '…'),
            'failed_at' => $job->failed_at,
        ]);

        return response()->json(['data' => $jobs]);
    }

    public function retry(string $uuid): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => '다시 실행하도록 큐에 넣었습니다.']);
    }

    public function forget(string $uuid): Response
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:forget', ['id' => $uuid]);

        return response()->noContent();
    }
}
