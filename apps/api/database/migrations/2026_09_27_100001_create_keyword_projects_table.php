<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('category')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('last_analyzed_at')->nullable();
            $table->string('analysis_version')->nullable();
            $table->timestamps();

            // 키워드 하나 = 분석 프로젝트 하나 (기획서 3.1)
            $table->unique(['user_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_projects');
    }
};
