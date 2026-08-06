<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('facebook_accounts', function (Blueprint $table) {
            $table->string('facebook_app_id')->nullable()->after('organization_id');
            $table->string('facebook_app_secret')->nullable()->after('facebook_app_id');
            $table->string('app_name')->nullable()->after('facebook_app_secret');
            $table->boolean('is_default_app')->default(false)->after('app_name');
            $table->string('callback_url')->nullable()->after('facebook_app_secret');
        });
    }

    public function down()
    {
        Schema::table('facebook_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'facebook_app_id',
                'facebook_app_secret',
                'app_name',
                'is_default_app',
                'callback_url'
            ]);
        });
    }
};
