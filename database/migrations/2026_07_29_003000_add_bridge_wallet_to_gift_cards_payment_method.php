<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE gift_cards MODIFY payment_method ENUM('stripe', 'believe_points', 'bridge_wallet') NULL");

            return;
        }

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('payment_method', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE gift_cards MODIFY payment_method ENUM('stripe', 'believe_points') NULL");

            return;
        }
    }
};
