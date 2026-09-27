<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // 초안 검증 결과(글자 수, 키워드 횟수, 경고)
            $table->json('draft_meta_json')->nullable()->after('content_text');
            $table->string('draft_error')->nullable()->after('draft_meta_json');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['draft_meta_json', 'draft_error']);
        });
    }
};
