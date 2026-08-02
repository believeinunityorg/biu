<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supporter_memberships', function (Blueprint $table) {
            $table->foreignId('membership_invitation_id')
                ->nullable()
                ->after('membership_plan_id')
                ->constrained('membership_invitations')
                ->nullOnDelete();
            $table->string('payment_status', 32)->default('waived')->after('status');
            $table->decimal('payment_amount', 10, 2)->nullable()->after('payment_status');
            $table->string('stripe_checkout_session_id')->nullable()->after('payment_amount');
            $table->timestamp('paid_at')->nullable()->after('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('supporter_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_invitation_id');
            $table->dropColumn([
                'payment_status',
                'payment_amount',
                'stripe_checkout_session_id',
                'paid_at',
            ]);
        });
    }
};
