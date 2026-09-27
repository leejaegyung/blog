<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_images', function (Blueprint $table) {
            $table->string('thumb_key')->nullable()->after('storage_key');
            // EXIF 촬영 시각. 방문 날짜 입력 제안에 쓴다(확정 사실로 쓰지 않음).
            $table->timestamp('taken_at')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('post_images', function (Blueprint $table) {
            $table->dropColumn(['thumb_key', 'taken_at']);
        });
    }
};
