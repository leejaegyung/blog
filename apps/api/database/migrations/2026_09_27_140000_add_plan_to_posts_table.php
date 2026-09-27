<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Writing Plan (기획서 6.1 STEP 1). 사용자가 고친 내용도 여기에 저장한다
            $table->json('plan_json')->nullable()->after('target_length');
            $table->string('plan_error')->nullable()->after('plan_json');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['plan_json', 'plan_error']);
        });
    }
};
