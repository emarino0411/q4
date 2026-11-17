<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->enum('pairing_mode', ['random', 'level', 'gender_balanced', 'win_loss'])->default('random')->after('number_of_courts');
            $table->enum('score_type', ['points', 'timed'])->default('points')->after('pairing_mode');
            $table->unsignedInteger('score_limit')->nullable()->after('score_type');
            $table->unsignedInteger('game_duration')->nullable()->after('score_limit'); // minutes
            $table->boolean('winners_stay')->default(false)->after('game_duration');
            $table->boolean('auto_assign')->default(true)->after('winners_stay');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['pairing_mode', 'score_type', 'score_limit', 'game_duration', 'winners_stay', 'auto_assign']);
        });
    }
};
