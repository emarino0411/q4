<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, ready, cancelled
            $table->timestamps();
        });

        Schema::create('request_group_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_group_players');
        Schema::dropIfExists('request_groups');
    }
};
