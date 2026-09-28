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
    public const PROVIDERS = [
        // 이 Mac의 Claude Code(구독 로그인)를 claude-bridge로 쓴다. API 키·크레딧이 필요 없다
        'claude_code' => [
            'label' => 'Claude 구독 (Claude Code)',
            'models' => ['opus', 'sonnet', 'haiku'],
            'key_prefix' => null,
            'console' => 'https://claude.ai/settings/usage',
        ],
        // 이 Mac의 Codex CLI(ChatGPT 구독 로그인)를 claude-bridge로 쓴다
        'codex' => [
            'label' => 'ChatGPT 구독 (Codex)',
            'models' => ['gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini'],
            'key_prefix' => null,
            'console' => 'https://chatgpt.com/codex/settings/usage',
        ],
        'anthropic' => [
            'label' => 'Anthropic (Claude)',
            'models' => ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'],
            'key_prefix' => 'sk-ant-',
            'console' => 'https://console.anthropic.com/settings/keys',
        ],
        'openai' => [
            'label' => 'OpenAI (GPT)',
            'models' => ['gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini'],
            'key_prefix' => 'sk-',
            'console' => 'https://platform.openai.com/api-keys',
        ],
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
            'anthropic_api_key' => $this->apiKey('anthropic') ?? '',
            'openai_api_key' => $this->apiKey('openai') ?? '',
            'route' => implode(',', $this->route()),
        ]));
    }

    private function storedKey(string $provider): ?string
    {
        $value = AppSetting::find("llm.{$provider}.api_key")?->value;

        return $value ? Crypt::decryptString($value) : null;
    }
}
