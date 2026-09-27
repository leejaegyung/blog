<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // AI가 처음 만든 초안. 사용자 수정본과 비교(Diff)·수정률 계산에 쓴다
            $table->json('content_original_json')->nullable()->after('content_json');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('content_original_json');
        });
    }
};
