<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('winner_player_id')->nullable()->constrained('players')->nullOnDelete()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Player::class, 'winner_player_id');
            $table->dropColumn('winner_player_id');
        });
    }
};
