<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('groups')) {
            return;
        }

        Schema::table('groups', function (Blueprint $table) {
            if (! Schema::hasColumn('groups', 'icon_image')) {
                $table->string('icon_image')->nullable()->after('cover_image');
            }
            if (! Schema::hasColumn('groups', 'visibility')) {
                $table->string('visibility', 32)->default('public')->after('category'); // public|private|hidden
            }
            if (! Schema::hasColumn('groups', 'join_policy')) {
                $table->string('join_policy', 32)->default('anyone')->after('visibility'); // anyone|approval|invite_only
            }
            if (! Schema::hasColumn('groups', 'posting_policy')) {
                $table->string('posting_policy', 32)->default('members')->after('join_policy'); // everyone|members|moderators|admins
            }
            if (! Schema::hasColumn('groups', 'rules')) {
                $table->json('rules')->nullable()->after('posting_policy');
            }
            if (! Schema::hasColumn('groups', 'allow_photos')) {
                $table->boolean('allow_photos')->default(true)->after('rules');
            }
            if (! Schema::hasColumn('groups', 'allow_videos')) {
                $table->boolean('allow_videos')->default(true)->after('allow_photos');
            }
            if (! Schema::hasColumn('groups', 'allow_documents')) {
                $table->boolean('allow_documents')->default(true)->after('allow_videos');
            }
            if (! Schema::hasColumn('groups', 'allow_polls')) {
                $table->boolean('allow_polls')->default(true)->after('allow_documents');
            }
            if (! Schema::hasColumn('groups', 'allow_events')) {
                $table->boolean('allow_events')->default(true)->after('allow_polls');
            }
        });

        if (Schema::hasTable('group_members') && ! Schema::hasColumn('group_members', 'membership_status')) {
            Schema::table('group_members', function (Blueprint $table) {
                $table->string('membership_status', 32)->default('active')->after('role'); // active|pending
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('group_members') && Schema::hasColumn('group_members', 'membership_status')) {
            Schema::table('group_members', function (Blueprint $table) {
                $table->dropColumn('membership_status');
            });
        }

        if (! Schema::hasTable('groups')) {
            return;
        }

        Schema::table('groups', function (Blueprint $table) {
            foreach ([
                'icon_image',
                'visibility',
                'join_policy',
                'posting_policy',
                'rules',
                'allow_photos',
                'allow_videos',
                'allow_documents',
                'allow_polls',
                'allow_events',
            ] as $column) {
                if (Schema::hasColumn('groups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
