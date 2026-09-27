<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Enums\ProjectStatus;
use App\Models\KeywordProject;
use App\Models\Post;
use App\Models\PostImage;
use App\Models\ReferenceDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 작업이 끝나지 않고 '진행 중'으로 남은 항목을 실패로 돌려 사용자가 다시 시도할 수 있게 한다.
 * 큐 작업은 재시도(3회·백오프)까지 길어야 20분 안팎이라 기본 기준을 30분으로 둔다.
 */
class RecoverStuckWork extends Command
{
    protected $signature = 'app:recover-stuck {--minutes=30}';

    protected $description = '오래 진행 중으로 남은 분석·계획·초안·사진 분석을 실패로 돌린다';

    public function handle(): int
    {
        $before = now()->subMinutes((int) $this->option('minutes'));
        $message = '시간이 너무 오래 걸려 중단했습니다. 다시 시도해 주세요.';

        $counts = [
            'plans' => Post::where('status', PostStatus::Planning)->where('updated_at', '<', $before)
                ->update(['status' => PostStatus::Failed, 'plan_error' => $message]),
            'drafts' => Post::where('status', PostStatus::Generating)->where('updated_at', '<', $before)
                ->update(['status' => PostStatus::Failed, 'draft_error' => $message]),
            'pipelines' => Post::where('pipeline_status', 'running')->where('updated_at', '<', $before)
                ->update(['pipeline_status' => 'failed', 'pipeline_error' => $message]),
            'analyses' => KeywordProject::where('status', ProjectStatus::Analyzing)->where('updated_at', '<', $before)
                ->update(['status' => ProjectStatus::Failed]),
            'references' => ReferenceDocument::where('parse_status', 'pending')->whereNotNull('source_url')
                ->where('updated_at', '<', $before)->update(['parse_status' => 'failed', 'error_message' => $message]),
            'photos' => PostImage::where('vision_status', 'pending')->where('updated_at', '<', $before)
                ->update(['vision_status' => 'failed', 'vision_error' => $message]),
        ];

        if (array_sum($counts) > 0) {
            Log::warning('stuck_work_recovered', $counts);
        }
        $this->info(collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(' '));

        return self::SUCCESS;
    }
}
