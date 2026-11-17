<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->enum('status', ['queued', 'playing', 'done'])->default('queued')->after('novice_play_count');
            $table->unsignedInteger('wins')->default(0)->after('status');
            $table->unsignedInteger('losses')->default(0)->after('wins');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['status', 'wins', 'losses']);
        });
    }
};
