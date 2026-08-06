<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_discussion_categories')) {
            Schema::create('organization_discussion_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->string('color', 32)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['organization_id', 'slug'], 'org_disc_cat_org_slug_uq');
                $table->index(['organization_id', 'is_active', 'sort_order'], 'org_disc_cat_org_active_idx');
            });
        }

        if (! Schema::hasTable('organization_discussions')) {
            Schema::create('organization_discussions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('organization_discussion_categories')->nullOnDelete();
                $table->string('title');
                $table->string('slug')->unique();
                $table->longText('body');
                $table->json('attachments')->nullable();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_pinned')->default(false);
                $table->boolean('is_locked')->default(false);
                $table->boolean('is_hidden')->default(false);
                $table->boolean('is_archived')->default(false);
                $table->boolean('posting_suspended')->default(false);
                $table->unsignedInteger('replies_count')->default(0);
                $table->unsignedInteger('views_count')->default(0);
                $table->unsignedInteger('reactions_count')->default(0);
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['organization_id', 'is_pinned', 'last_activity_at'], 'org_disc_org_pin_act_idx');
                $table->index(['organization_id', 'category_id'], 'org_disc_org_cat_idx');
                $table->index(['organization_id', 'created_by'], 'org_disc_org_author_idx');
                $table->index(['organization_id', 'is_hidden', 'is_archived'], 'org_disc_org_vis_idx');
            });
        }

        if (! Schema::hasTable('organization_discussion_replies')) {
            Schema::create('organization_discussion_replies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('discussion_id')->constrained('organization_discussions')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('organization_discussion_replies')->nullOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->longText('body');
                $table->json('attachments')->nullable();
                $table->boolean('is_hidden')->default(false);
                $table->unsignedInteger('reactions_count')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['discussion_id', 'created_at'], 'org_disc_reply_disc_created_idx');
                $table->index(['organization_id', 'user_id'], 'org_disc_reply_org_user_idx');
                $table->index('parent_id', 'org_disc_reply_parent_idx');
            });
        }

        if (! Schema::hasTable('organization_discussion_reactions')) {
            Schema::create('organization_discussion_reactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('emoji', 32);
                $table->string('reactable_type');
                $table->unsignedBigInteger('reactable_id');
                $table->timestamps();

                $table->unique(['user_id', 'reactable_type', 'reactable_id', 'emoji'], 'org_disc_reaction_unique');
                $table->index(['reactable_type', 'reactable_id'], 'org_disc_reaction_morph_idx');
                $table->index(['organization_id', 'reactable_type', 'reactable_id'], 'org_disc_reaction_org_idx');
            });
        }

        if (! Schema::hasTable('organization_discussion_reports')) {
            Schema::create('organization_discussion_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
                $table->string('reportable_type');
                $table->unsignedBigInteger('reportable_id');
                $table->string('reason', 64);
                $table->text('details')->nullable();
                $table->string('status', 32)->default('open');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['reportable_type', 'reportable_id'], 'org_disc_report_morph_idx');
                $table->index(['organization_id', 'status'], 'org_disc_report_org_status_idx');
            });
        }

        if (! Schema::hasTable('organization_discussion_follows')) {
            Schema::create('organization_discussion_follows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('discussion_id')->constrained('organization_discussions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('is_muted')->default(false);
                $table->timestamps();

                $table->unique(['discussion_id', 'user_id'], 'org_disc_follow_unique');
                $table->index(['organization_id', 'user_id'], 'org_disc_follow_org_user_idx');
            });
        }

        if (! Schema::hasTable('organization_discussion_views')) {
            Schema::create('organization_discussion_views', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('discussion_id')->constrained('organization_discussions')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('viewed_at');
                $table->timestamps();

                $table->index(['discussion_id', 'user_id'], 'org_disc_view_disc_user_idx');
                $table->index(['organization_id', 'viewed_at'], 'org_disc_view_org_viewed_idx');
            });
        }

        if (! Schema::hasTable('organization_moderation_logs')) {
            Schema::create('organization_moderation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('moderator_id')->constrained('users')->cascadeOnDelete();
                $table->string('action', 64);
                $table->string('target_type');
                $table->unsignedBigInteger('target_id');
                $table->text('reason')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'org_mod_log_target_idx');
                $table->index(['organization_id', 'action', 'created_at'], 'org_mod_log_org_action_idx');
                $table->index(['moderator_id', 'created_at'], 'org_mod_log_mod_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_moderation_logs');
        Schema::dropIfExists('organization_discussion_views');
        Schema::dropIfExists('organization_discussion_follows');
        Schema::dropIfExists('organization_discussion_reports');
        Schema::dropIfExists('organization_discussion_reactions');
        Schema::dropIfExists('organization_discussion_replies');
        Schema::dropIfExists('organization_discussions');
        Schema::dropIfExists('organization_discussion_categories');
    }
};
