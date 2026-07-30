<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('onboarding_purpose', 64)->nullable()->after('volunteer_interest_statement');
            $table->unsignedTinyInteger('onboarding_step')->default(1)->after('onboarding_purpose');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_step');
            $table->json('notification_preferences')->nullable()->after('proximity_notifications_enabled');
        });

        DB::table('users')
            ->where('role', 'user')
            ->whereNotNull('dob')
            ->whereNotNull('city')
            ->whereNotNull('state')
            ->whereNull('onboarding_completed_at')
            ->update([
                'onboarding_completed_at' => now(),
                'onboarding_step' => 12,
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_purpose',
                'onboarding_step',
                'onboarding_completed_at',
                'notification_preferences',
            ]);
        });
    }
};
