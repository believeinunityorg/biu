<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supporter_memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('supporter_memberships', 'membership_invitation_id')) {
                $table->foreignId('membership_invitation_id')
                    ->nullable()
                    ->after('membership_plan_id')
                    ->constrained('membership_invitations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('supporter_memberships', 'payment_status')) {
                $table->string('payment_status', 32)->default('waived')->after('status');
            }

            if (! Schema::hasColumn('supporter_memberships', 'payment_amount')) {
                $table->decimal('payment_amount', 10, 2)->nullable()->after('payment_status');
            }

            if (! Schema::hasColumn('supporter_memberships', 'stripe_checkout_session_id')) {
                $table->string('stripe_checkout_session_id')->nullable()->after('payment_amount');
            }

            if (! Schema::hasColumn('supporter_memberships', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('stripe_checkout_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supporter_memberships', function (Blueprint $table) {
            if (Schema::hasColumn('supporter_memberships', 'membership_invitation_id')) {
                $table->dropConstrainedForeignId('membership_invitation_id');
            }

            foreach (['payment_status', 'payment_amount', 'stripe_checkout_session_id', 'paid_at'] as $column) {
                if (Schema::hasColumn('supporter_memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
