<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * AI 공급자 API 키와 시도 순서. 관리 화면 값이 우선이고, 없으면 .env 값을 쓴다.
 * 키는 APP_KEY로 암호화해 DB에 두고, 화면에는 가린 값만 보낸다. 워커에는 요청 헤더로 넘긴다(재시작 없이 적용).
 */
class LlmSettings
{
    /*
     * 글쓰기는 이 Mac의 구독(Claude Code·Codex CLI)으로 한다. models는 연결기가 목록을 못 줄 때 쓰는 기본 목록이다.
     * [API 연결 꺼 둠 2026-09-28] Anthropic·OpenAI API 키 공급자는 주석으로 남겨 둔다. 다시 쓰려면 아래 두 항목과
     * routes/api.php의 키 관리 경로, 워커 app/llm/factory.py의 API 어댑터 등록 주석을 함께 푼다.
     */
    public const PROVIDERS = [
        // 이 Mac의 Claude Code(구독 로그인)를 claude-bridge로 쓴다
        'claude_code' => [
            'label' => 'Claude 구독 (Claude Code)',
            'models' => [
                ['id' => 'opus', 'label' => 'Opus', 'description' => '가장 좋은 품질(구독 한도를 가장 많이 씀)'],
                ['id' => 'sonnet', 'label' => 'Sonnet', 'description' => '품질과 속도의 균형'],
                ['id' => 'haiku', 'label' => 'Haiku', 'description' => '빠르고 가벼움'],
            ],
            'key_prefix' => null,
            'console' => 'https://claude.ai/settings/usage',
        ],
        // 이 Mac의 Codex CLI(ChatGPT 구독 로그인)를 claude-bridge로 쓴다
        'codex' => [
            'label' => 'ChatGPT 구독 (Codex)',
            'models' => [
                ['id' => 'gpt-6-astra', 'label' => 'GPT-6-Astra', 'description' => '가장 좋은 품질'],
                ['id' => 'gpt-6-sol', 'label' => 'GPT-6-Sol', 'description' => '균형'],
                ['id' => 'gpt-6-luna', 'label' => 'GPT-6-Luna', 'description' => '빠르고 가벼움'],
                ['id' => 'gpt-5.5', 'label' => 'GPT-5.5', 'description' => '이전 모델'],
            ],
            'key_prefix' => null,
            'console' => 'https://chatgpt.com/codex/settings/usage',
        ],
        // 'anthropic' => [
        //     'label' => 'Anthropic (Claude)',
        //     'models' => [['id' => 'claude-opus-5', 'label' => 'claude-opus-5', 'description' => ''], ['id' => 'claude-sonnet-5', 'label' => 'claude-sonnet-5', 'description' => '']],
        //     'key_prefix' => 'sk-ant-',
        //     'console' => 'https://console.anthropic.com/settings/keys',
        // ],
        // 'openai' => [
        //     'label' => 'OpenAI (GPT)',
        //     'models' => [['id' => 'gpt-5.5', 'label' => 'gpt-5.5', 'description' => '']],
        //     'key_prefix' => 'sk-',
        //     'console' => 'https://platform.openai.com/api-keys',
        // ],
    ];

    public function apiKey(string $provider): ?string
    {
        return $this->storedKey($provider) ?? (config("services.llm.{$provider}_key") ?: null);
    }

    /** @return 'admin'|'env'|'bridge'|'none' */
    public function source(string $provider): string
    {
        if (self::PROVIDERS[$provider]['key_prefix'] === null) {
            return config('services.llm.claude_bridge_token') ? 'bridge' : 'none';
        }

        return match (true) {
            $this->storedKey($provider) !== null => 'admin',
            (bool) config("services.llm.{$provider}_key") => 'env',
            default => 'none',
        };
    }

    public function setApiKey(string $provider, string $key): void
    {
        AppSetting::updateOrCreate(['key' => "llm.{$provider}.api_key"], ['value' => Crypt::encryptString($key)]);
    }

    public function clearApiKey(string $provider): void
    {
        AppSetting::whereKey("llm.{$provider}.api_key")->delete();
    }

    public function keyUpdatedAt(string $provider): ?string
    {
        return AppSetting::find("llm.{$provider}.api_key")?->updated_at?->toIso8601String();
    }

    /** @return list<string> "provider:model" 순서 */
    public function route(): array
    {
        $stored = AppSetting::find('llm.route')?->value;
        $route = $stored ? json_decode($stored, true) : explode(',', (string) config('services.llm.route'));

        return array_values(array_filter(array_map('trim', $route)));
    }

    public function routeSource(): string
    {
        return AppSetting::find('llm.route') ? 'admin' : 'env';
    }

    /** @param  list<string>  $route */
    public function setRoute(array $route): void
    {
        AppSetting::updateOrCreate(['key' => 'llm.route'], ['value' => json_encode(array_values($route))]);
    }

    public function resetRoute(): void
    {
        AppSetting::whereKey('llm.route')->delete();
    }

    public static function mask(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        return mb_strlen($key) <= 12 ? str_repeat('•', 8) : mb_substr($key, 0, 7).'…'.mb_substr($key, -4);
    }

    /** 워커 요청 헤더 X-LLM-Config 값 */
    public function workerHeader(): string
    {
        return base64_encode(json_encode([
            // [API 연결 꺼 둠] 키는 보내지 않는다(워커도 API 어댑터를 등록하지 않는다)
            // 'anthropic_api_key' => $this->apiKey('anthropic') ?? '',
            // 'openai_api_key' => $this->apiKey('openai') ?? '',
            'route' => implode(',', $this->route()),
        ]));
    }

    private function storedKey(string $provider): ?string
    {
        $value = AppSetting::find("llm.{$provider}.api_key")?->value;

        return $value ? Crypt::decryptString($value) : null;
    }
}
