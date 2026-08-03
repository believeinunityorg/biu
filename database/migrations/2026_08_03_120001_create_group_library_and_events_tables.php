<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('group_library_items')) {
            Schema::create('group_library_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
                $table->foreignId('uploader_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 32); // photo | video | document
                $table->string('path');
                $table->string('original_name');
                $table->string('mime', 127)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->string('title')->nullable();
                $table->text('caption')->nullable();
                $table->string('visibility_status', 32)->default('visible');
                $table->timestamps();

                $table->index(['group_id', 'type', 'created_at']);
            });
        }

        // group_events may already exist from an earlier schema:
        // created_by, name, start_date, end_date, location, poster_image, status
        if (! Schema::hasTable('group_events')) {
            Schema::create('group_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->dateTime('start_date');
                $table->dateTime('end_date')->nullable();
                $table->string('location')->nullable();
                $table->string('location_url')->nullable();
                $table->string('poster_image')->nullable();
                $table->string('status', 32)->default('upcoming');
                $table->timestamps();

                $table->index(['group_id', 'start_date']);
            });
        } elseif (! Schema::hasColumn('group_events', 'location_url')) {
            Schema::table('group_events', function (Blueprint $table) {
                $table->string('location_url')->nullable()->after('location');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('group_events') && Schema::hasColumn('group_events', 'location_url')) {
            Schema::table('group_events', function (Blueprint $table) {
                $table->dropColumn('location_url');
            });
        }

        Schema::dropIfExists('group_library_items');
    }
};
