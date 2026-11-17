<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('pending_pairing_mode')->nullable()->after('pairing_mode');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->string('queued_mode')->nullable()->after('queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('pending_pairing_mode');
        });
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('queued_mode');
        });
    }
};
