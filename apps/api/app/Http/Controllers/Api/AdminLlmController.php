<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\AiWorkerUnavailableException;
use App\Services\AiWorker\GenerationRecorder;
use App\Services\LlmSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** 관리 화면의 AI 공급자 설정. 키 원문은 절대 응답에 넣지 않는다. */
class AdminLlmController extends Controller
{
    public function __construct(private LlmSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->state()]);
    }

    public function updateKey(Request $request, string $provider): JsonResponse
    {
        $this->ensureProvider($provider);
        $prefix = LlmSettings::PROVIDERS[$provider]['key_prefix'];
        $key = trim((string) $request->validate(['api_key' => ['required', 'string', 'min:20', 'max:300']])['api_key']);

        if (! str_starts_with($key, $prefix) || preg_match('/\s/', $key)) {
            throw ValidationException::withMessages(['api_key' => "{$prefix}로 시작하는 키를 공백 없이 넣어 주세요."]);
        }

        $this->settings->setApiKey($provider, $key);

        return response()->json(['data' => $this->state()]);
    }

    public function deleteKey(string $provider): JsonResponse
    {
        $this->ensureProvider($provider);
        $this->settings->clearApiKey($provider);

        return response()->json(['data' => $this->state()]);
    }

    public function updateRoute(Request $request): JsonResponse
    {
        if ($request->boolean('reset')) {
            $this->settings->resetRoute();

            return response()->json(['data' => $this->state()]);
        }

        $data = $request->validate([
            'route' => ['required', 'array', 'min:1', 'max:6'],
            'route.*.provider' => ['required', Rule::in(array_keys(LlmSettings::PROVIDERS))],
            'route.*.model' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9.\-]*$/'],
        ], ['route.*.model.regex' => '모델 이름은 영문 소문자·숫자·점·하이픈만 쓸 수 있습니다.']);

        $route = array_values(array_unique(array_map(fn ($t) => "{$t['provider']}:{$t['model']}", $data['route'])));
        $this->settings->setRoute($route);

        return response()->json(['data' => $this->state()]);
    }

    /** 지정한 대상(없으면 현재 순서)으로 연결을 확인한다. 호출 기록은 사용량에 남는다. */
    public function test(Request $request, AiWorkerClient $worker, GenerationRecorder $recorder): JsonResponse
    {
        $data = $request->validate(['target' => ['nullable', 'string', 'regex:/^(anthropic|openai):[a-z0-9][a-z0-9.\-]*$/']]);

        try {
            $result = $worker->pingLlm(array_filter([$data['target'] ?? null]));
        } catch (AiWorkerUnavailableException) {
            return response()->json(['message' => 'AI Worker에 연결하지 못했습니다.'], 503);
        }

        $recorder->record($result['generations'], purpose: 'ping');

        return response()->json(['data' => [
            'ok' => $result['text'] !== null,
            'reply' => $result['text'],
            'attempts' => array_map(fn ($g) => [
                'provider' => $g['provider'],
                'model' => $g['model'],
                'status' => $g['status'],
                'error_kind' => $g['error_kind'] ?? null,
                'latency_ms' => $g['latency_ms'],
            ], $result['generations']),
        ]]);
    }

    private function state(): array
    {
        return [
            'providers' => collect(LlmSettings::PROVIDERS)->map(fn ($meta, $provider) => [
                'provider' => $provider,
                'label' => $meta['label'],
                'models' => $meta['models'],
                'key_prefix' => $meta['key_prefix'],
                'console' => $meta['console'],
                'source' => $this->settings->source($provider),
                'masked_key' => LlmSettings::mask($this->settings->apiKey($provider)),
                'updated_at' => $this->settings->keyUpdatedAt($provider),
            ])->values(),
            'route' => array_map(function ($spec) {
                [$provider, $model] = array_pad(explode(':', $spec, 2), 2, '');

                return ['provider' => $provider, 'model' => $model];
            }, $this->settings->route()),
            'route_source' => $this->settings->routeSource(),
        ];
    }

    private function ensureProvider(string $provider): void
    {
        abort_unless(array_key_exists($provider, LlmSettings::PROVIDERS), 404);
    }
}
