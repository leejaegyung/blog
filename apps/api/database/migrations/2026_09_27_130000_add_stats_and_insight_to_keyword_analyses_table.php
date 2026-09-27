<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_analyses', function (Blueprint $table) {
            // 코드로 집계한 참고자료 분포(LLM 없이 항상 채워진다)
            $table->json('stats_json')->nullable()->after('must_answer_json');
            // LLM 해석 전체. 모든 LLM 대상이 실패하면 null이고 insight_error에 사유를 남긴다
            $table->json('insight_json')->nullable()->after('stats_json');
            $table->string('insight_error')->nullable()->after('insight_json');
            $table->string('prompt_version')->nullable()->after('insight_error');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_analyses', function (Blueprint $table) {
            $table->dropColumn(['stats_json', 'insight_json', 'insight_error', 'prompt_version']);
        });
    }
};
