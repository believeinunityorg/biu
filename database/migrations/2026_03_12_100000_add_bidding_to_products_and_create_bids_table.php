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
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'pricing_model')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('pricing_model', 32)->default('fixed')->after('unit_price'); // fixed, auction, blind_bid, offer
                // Auction / open bidding
                $table->decimal('starting_bid', 10, 2)->nullable()->after('pricing_model');
                $table->decimal('buy_now_price', 10, 2)->nullable()->after('starting_bid');
                $table->decimal('bid_increment', 10, 2)->nullable()->after('buy_now_price');
                $table->timestamp('auction_start')->nullable()->after('bid_increment');
                $table->timestamp('auction_end')->nullable()->after('auction_start');
                $table->boolean('auto_extend')->default(false)->after('auction_end');
                // Blind bid (sealed)
                $table->string('blind_bid_type', 32)->nullable()->after('auto_extend'); // sealed, sealed_revisable, vickrey
                $table->decimal('min_bid', 10, 2)->nullable()->after('blind_bid_type');
                $table->decimal('reserve_price', 10, 2)->nullable()->after('min_bid');
                $table->timestamp('bid_deadline')->nullable()->after('reserve_price');
                $table->string('winner_notification', 64)->nullable()->after('bid_deadline'); // email,in_app
                $table->string('winner_payment_window', 32)->nullable()->after('winner_notification'); // 24h, 48h, 72h
                $table->boolean('offer_to_next_if_unpaid')->default(true)->after('winner_payment_window');
                // Winner (set after bid close)
                $table->unsignedBigInteger('winner_user_id')->nullable()->after('offer_to_next_if_unpaid');
                $table->unsignedBigInteger('winning_bid_id')->nullable()->after('winner_user_id');
                $table->timestamp('winner_payment_deadline')->nullable()->after('winning_bid_id');
                $table->string('winner_status', 32)->nullable()->after('winner_payment_deadline'); // pending_payment, paid, forfeited
            });
        }

        if (! Schema::hasTable('bids')) {
            Schema::create('bids', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->decimal('bid_amount', 10, 2);
                $table->string('status', 32)->default('active'); // active, withdrawn, invalid, winning, lost
                $table->timestamp('submitted_at')->useCurrent();
                $table->timestamps();

                $table->index(['product_id', 'status']);
                $table->index(['user_id', 'product_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'pricing_model', 'starting_bid', 'buy_now_price', 'bid_increment',
                'auction_start', 'auction_end', 'auto_extend',
                'blind_bid_type', 'min_bid', 'reserve_price', 'bid_deadline',
                'winner_notification', 'winner_payment_window', 'offer_to_next_if_unpaid',
                'winner_user_id', 'winning_bid_id', 'winner_payment_deadline', 'winner_status',
            ]);
        });
        Schema::dropIfExists('bids');
    }
};
