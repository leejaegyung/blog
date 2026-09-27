<?php

namespace App\Console\Commands;

use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\AiWorkerUnavailableException;
use App\Services\AiWorker\GenerationRecorder;
use Illuminate\Console\Command;

class LlmPing extends Command
{
    protected $signature = 'app:llm-ping {--target=* : provider:model (예: openai:gpt-5.5). 생략하면 기본 순서}';

    protected $description = 'AI Worker를 거쳐 LLM 공급자 연결을 확인하고 호출 기록을 남긴다';

    public function handle(AiWorkerClient $worker, GenerationRecorder $recorder): int
    {
        try {
            $response = $worker->pingLlm($this->option('target'));
        } catch (AiWorkerUnavailableException $e) {
            $this->error('AI Worker에 연결할 수 없습니다: '.$e->getMessage());

            return self::FAILURE;
        }

        $recorder->record($response['generations'], purpose: 'ping');

        $this->table(
            ['공급자', '모델', '결과', '입력 토큰', '출력 토큰', 'ms', '오류'],
            array_map(fn ($g) => [
                $g['provider'], $g['model'], $g['status'], $g['input_tokens'] ?? 0,
                $g['output_tokens'] ?? 0, $g['latency_ms'], $g['error_kind'] ?? '',
            ], $response['generations']),
        );

        if ($response['text'] === null) {
            $this->error('모든 대상이 실패했습니다.');

            return self::FAILURE;
        }

        $this->info('응답: '.$response['text']);

        return self::SUCCESS;
    }
}
