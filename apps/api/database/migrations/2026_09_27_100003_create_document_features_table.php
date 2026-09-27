<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_document_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('char_count')->default(0);
            $table->unsignedInteger('paragraph_count')->default(0);
            $table->unsignedInteger('image_count')->default(0);
            $table->unsignedInteger('keyword_frequency')->default(0);
            $table->json('features_json')->nullable();
            $table->string('analyzer_version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_features');
    }
};
