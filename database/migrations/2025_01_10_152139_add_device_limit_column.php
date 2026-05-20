<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_plan', 'device_limit')) {
                $table->unsignedInteger('device_limit')->nullable()->after('speed_limit');
            }
        });
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'device_limit')) {
                $table->integer('device_limit')->nullable()->after('expired_at');
            }
            if (!Schema::hasColumn('v2_user', 'online_count')) {
                $table->integer('online_count')->nullable()->after('device_limit');
            }
            if (!Schema::hasColumn('v2_user', 'last_online_at')) {
                $table->timestamp('last_online_at')->nullable()->after('online_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (Schema::hasColumn('v2_user', 'device_limit')) {
                $table->dropColumn('device_limit');
            }
            if (Schema::hasColumn('v2_user', 'online_count')) {
                $table->dropColumn('online_count');
            }
            if (Schema::hasColumn('v2_user', 'last_online_at')) {
                $table->dropColumn('last_online_at');
            }
        });
        Schema::table('v2_plan', function (Blueprint $table) {
            if (Schema::hasColumn('v2_plan', 'device_limit')) {
                $table->dropColumn('device_limit');
            }
        });
    }
};
