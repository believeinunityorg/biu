<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->decimal('platform_fee', 18, 8)->nullable()->after('amount');
            $table->decimal('platform_fee_biu_share', 18, 8)->nullable()->after('platform_fee');
            $table->decimal('platform_fee_org_share', 18, 8)->nullable()->after('platform_fee_biu_share');
        });
    }

    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropColumn([
                'platform_fee',
                'platform_fee_biu_share',
                'platform_fee_org_share',
            ]);
        });
    }
};
