<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnNetworkAndNetworkSettingsToV2ServerTrojan extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('v2_server_trojan', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server_trojan', 'network')) {
                $table->string('network', 11)->default('tcp')->after('server_name')->comment('传输协议');
            }
            if (!Schema::hasColumn('v2_server_trojan', 'networkSettings')) {
                $table->text('networkSettings')->nullable()->after('network')->comment('传输协议配置');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('v2_server_trojan', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server_trojan', 'network')) {
                $table->dropColumn('network');
            }
            if (Schema::hasColumn('v2_server_trojan', 'networkSettings')) {
                $table->dropColumn('networkSettings');
            }
        });
    }
}
