<?php

use App\Models\CommunityContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('community_contents')) {
            return;
        }

        if (! Schema::hasColumn('community_contents', 'slug')) {
            Schema::table('community_contents', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('title');
            });
        }

        CommunityContent::query()
            ->where(function ($q) {
                $q->whereNull('slug')->orWhere('slug', '');
            })
            ->orderBy('id')
            ->each(function (CommunityContent $content) {
                $base = Str::slug((string) $content->title);
                if ($base === '') {
                    $base = 'post';
                }
                $slug = $base;
                $counter = 2;
                while (
                    CommunityContent::query()
                        ->where('slug', $slug)
                        ->where('id', '!=', $content->id)
                        ->exists()
                ) {
                    $slug = $base.'-'.$counter;
                    $counter++;
                }
                $content->forceFill(['slug' => $slug])->saveQuietly();
            });

        Schema::table('community_contents', function (Blueprint $table) {
            $indexes = Schema::getIndexes('community_contents');
            $hasUnique = collect($indexes)->contains(function (array $index) {
                return ($index['unique'] ?? false)
                    && ($index['columns'] ?? []) === ['slug'];
            });
            if (! $hasUnique) {
                $table->unique('slug');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('community_contents') || ! Schema::hasColumn('community_contents', 'slug')) {
            return;
        }

        Schema::table('community_contents', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
