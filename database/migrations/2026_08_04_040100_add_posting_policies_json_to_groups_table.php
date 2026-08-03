<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('groups')) {
            return;
        }

        if (! Schema::hasColumn('groups', 'posting_policies')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->json('posting_policies')->nullable()->after('join_policy');
            });
        }

        if (Schema::hasColumn('groups', 'posting_policy')) {
            DB::table('groups')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $legacy = is_string($row->posting_policy ?? null) && $row->posting_policy !== ''
                        ? $row->posting_policy
                        : 'members';

                    DB::table('groups')->where('id', $row->id)->update([
                        'posting_policies' => json_encode([$legacy]),
                    ]);
                }
            });
        } else {
            DB::table('groups')->whereNull('posting_policies')->update([
                'posting_policies' => json_encode(['members']),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('groups') && Schema::hasColumn('groups', 'posting_policies')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->dropColumn('posting_policies');
            });
        }
    }
};
