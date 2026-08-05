<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('church_profiles')) {
            return;
        }

        Schema::create('church_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('denomination')->nullable();
            $table->string('senior_pastor_name')->nullable();
            $table->text('service_times')->nullable();
            $table->text('ministries')->nullable();
            $table->string('worship_location')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_profiles');
    }
};
