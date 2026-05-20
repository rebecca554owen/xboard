<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnVersionAndIsObfsToServerHysteriaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('v2_server_hysteria', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server_hysteria', 'version')) {
                $table->tinyInteger('version', false, true)->default(1)->comment('hysteria版本,Version:1\2');
            }
            if (!Schema::hasColumn('v2_server_hysteria', 'is_obfs')) {
                $table->boolean('is_obfs')->default(true)->comment('是否开启obfs');
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
        Schema::table('v2_server_hysteria', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server_hysteria', 'version')) {
                $table->dropColumn('version');
            }
            if (Schema::hasColumn('v2_server_hysteria', 'is_obfs')) {
                $table->dropColumn('is_obfs');
            }
        });
    }
}
