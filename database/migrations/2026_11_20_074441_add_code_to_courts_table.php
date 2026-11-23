<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->string('code', 8)->nullable()->unique()->after('active');
        });

        // Backfill existing courts
        \App\Models\Court::whereNull('code')->each(function ($court) {
            $court->update(['code' => strtoupper(Str::random(6))]);
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
