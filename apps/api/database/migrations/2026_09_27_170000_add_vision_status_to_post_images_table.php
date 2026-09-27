<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_images', function (Blueprint $table) {
            // null(분석 안 함) | pending | done | failed
            $table->string('vision_status')->nullable()->after('vision_json');
            $table->string('vision_error')->nullable()->after('vision_status');
        });
    }

    public function down(): void
    {
        Schema::table('post_images', function (Blueprint $table) {
            $table->dropColumn(['vision_status', 'vision_error']);
        });
    }
};
