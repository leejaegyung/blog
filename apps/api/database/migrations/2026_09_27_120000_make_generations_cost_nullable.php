<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 금액은 계산하지 않고 토큰만 기록한다(1인용 결정, 2026-09-27). 0원으로 오해되지 않게 null로 둔다.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('cost', 10, 6)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('cost', 10, 6)->default(0)->change();
        });
    }
};
