<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->foreignId('request_group_id')->nullable()->constrained('request_groups')->nullOnDelete()->after('pairing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\RequestGroup::class, 'request_group_id');
            $table->dropColumn('request_group_id');
        });
    }
};
