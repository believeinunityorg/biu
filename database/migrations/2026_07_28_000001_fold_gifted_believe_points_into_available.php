<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gift BP becomes reporting-only: fold any remaining gifted wallet into Available BP.
 * gifted_believe_points stays as the "Gift BP received" counter (not spendable separately).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'gifted_believe_points') || ! Schema::hasColumn('users', 'believe_points')) {
            return;
        }

        DB::table('users')
            ->where('gifted_believe_points', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $gifted = round((float) ($user->gifted_believe_points ?? 0), 2);
                    if ($gifted <= 0) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'believe_points' => round((float) ($user->believe_points ?? 0) + $gifted, 2),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible: cannot safely split Available back into purchased vs gifted.
    }
};
