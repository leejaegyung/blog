<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_project_id')->constrained()->cascadeOnDelete();
            $table->string('primary_intent')->nullable();
            $table->json('related_keywords_json')->nullable();
            $table->json('common_topics_json')->nullable();
            $table->json('recommended_outline_json')->nullable();
            $table->json('title_patterns_json')->nullable();
            $table->json('must_answer_json')->nullable();
            // 캐시 키 = keyword + source_hash + analyzer_version (기획서 21장)
            $table->string('source_hash', 64)->nullable();
            $table->string('analyzer_version');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['keyword_project_id', 'source_hash', 'analyzer_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_analyses');
    }
};
