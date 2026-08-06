<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_announcements')) {
            Schema::create('organization_announcements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('title');
                $table->string('slug')->unique();
                $table->longText('message');
                $table->string('category', 100)->nullable();
                $table->string('cover_image')->nullable();
                $table->json('attachments')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->boolean('allow_comments')->default(true);
                $table->string('status', 32)->default('draft'); // draft|scheduled|published|archived|hidden
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['organization_id', 'status'], 'org_ann_org_status_idx');
                $table->index(['organization_id', 'is_pinned', 'published_at'], 'org_ann_org_pin_pub_idx');
                $table->index(['organization_id', 'scheduled_at'], 'org_ann_org_sched_idx');
                $table->index(['organization_id', 'expires_at'], 'org_ann_org_exp_idx');
                $table->index('created_by', 'org_ann_created_by_idx');
            });
        }

        if (! Schema::hasTable('organization_announcement_comments')) {
            Schema::create('organization_announcement_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('announcement_id')->constrained('organization_announcements')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('organization_announcement_comments')->nullOnDelete();
                $table->text('body');
                $table->boolean('is_hidden')->default(false);
                $table->boolean('is_locked')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['announcement_id', 'created_at'], 'org_ann_cmt_ann_created_idx');
                $table->index(['organization_id', 'user_id'], 'org_ann_cmt_org_user_idx');
            });
        }

        if (! Schema::hasTable('organization_announcement_histories')) {
            Schema::create('organization_announcement_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('announcement_id')->constrained('organization_announcements')->cascadeOnDelete();
                $table->foreignId('edited_by')->constrained('users')->cascadeOnDelete();
                $table->string('title');
                $table->longText('message');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['announcement_id', 'created_at'], 'org_ann_hist_ann_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_announcement_histories');
        Schema::dropIfExists('organization_announcement_comments');
        Schema::dropIfExists('organization_announcements');
    }
};
