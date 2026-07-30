<?php

use Database\Seeders\MenuItemSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        (new MenuItemSeeder)->run();
    }

    public function down(): void
    {
        // Menu items are shared catalog data; do not delete on rollback.
    }
};
