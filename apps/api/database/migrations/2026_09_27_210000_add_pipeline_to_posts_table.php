<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // '글 만들기' 한 번으로 이어서 도는 작업(사진 분석→키워드 분석→계획→초안→검사)의 진행 상태
            $table->string('pipeline_status')->nullable()->after('status'); // running | done | failed
            $table->string('pipeline_step')->nullable()->after('pipeline_status'); // vision | analysis | plan | draft | quality
            $table->string('pipeline_error')->nullable()->after('pipeline_step');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['pipeline_status', 'pipeline_step', 'pipeline_error']);
        });
    }
};
