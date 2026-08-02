<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            if (! Schema::hasColumn('groups', 'cover_image')) {
                $table->string('cover_image')->nullable()->after('description');
            }
            if (! Schema::hasColumn('groups', 'category')) {
                $table->string('category', 100)->nullable()->after('cover_image');
            }
            if (! Schema::hasColumn('groups', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('status');
            }
            if (! Schema::hasColumn('groups', 'is_pinned')) {
                $table->boolean('is_pinned')->default(false)->after('is_featured');
            }
            if (! Schema::hasColumn('groups', 'is_hidden_on_parent')) {
                $table->boolean('is_hidden_on_parent')->default(false)->after('is_pinned');
            }
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            foreach (['cover_image', 'category', 'is_featured', 'is_pinned', 'is_hidden_on_parent'] as $column) {
                if (Schema::hasColumn('groups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
