<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            if (! Schema::hasColumn('gift_cards', 'pin')) {
                $table->string('pin', 64)->nullable()->after('card_number');
            }
        });

        Schema::table('gift_cards', function (Blueprint $table) {
            // Phaze card numbers / codes can exceed 16 characters for some brands.
            $table->string('card_number', 64)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            if (Schema::hasColumn('gift_cards', 'pin')) {
                $table->dropColumn('pin');
            }
        });

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('card_number', 16)->nullable()->change();
        });
    }
};
