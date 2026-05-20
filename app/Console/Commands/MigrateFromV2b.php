<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateFromV2b extends Command
{
    protected $signature = 'migrateFromV2b {version?}';
    protected $description = '供不同版本V2b迁移到本项目的脚本';

    public function handle()
    {
        $version = $this->argument('version');
        if($version === 'config'){
            $this->MigrateV2ConfigToV2Settings();
            return self::SUCCESS;
        }

        $sqlCommands = [
            'dev231027',
            '1.7.4',
            '1.7.3',
            'wyx2685',
        ];

        if (!$version) {
            $version = $this->choice('请选择你迁移前的V2board版本:', $sqlCommands);
        }

        if (!in_array($version, $sqlCommands, true)) {
            $this->error("你所输入的版本未找到");
            return self::FAILURE;
        }

        try {
            match ($version) {
                'dev231027' => $this->migrateDev231027(),
                '1.7.4' => $this->migrate174(),
                '1.7.3' => $this->migrate173(),
                'wyx2685' => $this->migrateWyx2685(),
            };

            $this->info('1️⃣、数据库差异矫正成功');

            // 初始化数据库迁移
            $this->call('db:seed', ['--class' => 'OriginV2bMigrationsTableSeeder']);
            $this->info('2️⃣、数据库迁移记录初始化成功');

            $this->call('xboard:update');
            $this->info('3️⃣、更新成功');

            $this->info("🎉：成功从 $version 迁移到Xboard");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('迁移失败'. $e->getMessage() );
            return self::FAILURE;
        }
    }

    protected function migrateDev231027(): void
    {
        $this->addColumnIfMissing('v2_order', 'surplus_order_ids', fn (Blueprint $table) => $table->text('surplus_order_ids')->nullable());
        $this->dropColumnIfExists('v2_plan', 'daily_unit_price');
        $this->dropColumnIfExists('v2_plan', 'transfer_unit_price');
        $this->dropColumnIfExists('v2_server_hysteria', 'ignore_client_bandwidth');
        $this->dropColumnIfExists('v2_server_hysteria', 'obfs_type');
    }

    protected function migrate174(): void
    {
        if (!Schema::hasTable('v2_server_vless')) {
            DB::statement(<<<SQL
CREATE TABLE `v2_server_vless` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `group_id` TEXT NOT NULL,
    `route_id` TEXT NULL,
    `name` VARCHAR(255) NOT NULL,
    `parent_id` INT NULL,
    `host` VARCHAR(255) NOT NULL,
    `port` INT NOT NULL,
    `server_port` INT NOT NULL,
    `tls` BOOLEAN NOT NULL,
    `tls_settings` TEXT NULL,
    `flow` VARCHAR(64) NULL,
    `network` VARCHAR(11) NOT NULL,
    `network_settings` TEXT NULL,
    `tags` TEXT NULL,
    `rate` VARCHAR(11) NOT NULL,
    `show` BOOLEAN DEFAULT FALSE,
    `sort` INT NULL,
    `created_at` INT NOT NULL,
    `updated_at` INT NOT NULL
);
SQL);
        }
    }

    protected function migrate173(): void
    {
        $this->renameTableIfNeeded('v2_stat_order', 'v2_stat');
        $this->renameColumnIfNeeded('v2_stat', 'order_amount', 'paid_total');
        $this->renameColumnIfNeeded('v2_stat', 'order_count', 'paid_count');
        $this->renameColumnIfNeeded('v2_stat', 'commission_amount', 'commission_total');

        $this->addColumnIfMissing('v2_stat', 'order_count', fn (Blueprint $table) => $table->integer('order_count')->nullable());
        $this->addColumnIfMissing('v2_stat', 'order_total', fn (Blueprint $table) => $table->integer('order_total')->nullable());
        $this->addColumnIfMissing('v2_stat', 'register_count', fn (Blueprint $table) => $table->integer('register_count')->nullable());
        $this->addColumnIfMissing('v2_stat', 'invite_count', fn (Blueprint $table) => $table->integer('invite_count')->nullable());
        $this->addColumnIfMissing('v2_stat', 'transfer_used_total', fn (Blueprint $table) => $table->string('transfer_used_total', 32)->nullable());

        if (!Schema::hasTable('v2_log')) {
            DB::statement(<<<SQL
CREATE TABLE `v2_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` TEXT NOT NULL,
    `level` VARCHAR(11) NULL,
    `host` VARCHAR(255) NULL,
    `uri` VARCHAR(255) NOT NULL,
    `method` VARCHAR(11) NOT NULL,
    `data` TEXT NULL,
    `ip` VARCHAR(128) NULL,
    `context` TEXT NULL,
    `created_at` INT NOT NULL,
    `updated_at` INT NOT NULL
);
SQL);
        }

        if (!Schema::hasTable('v2_server_hysteria')) {
            DB::statement(<<<SQL
CREATE TABLE `v2_server_hysteria` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `group_id` VARCHAR(255) NOT NULL,
    `route_id` VARCHAR(255) NULL,
    `name` VARCHAR(255) NOT NULL,
    `parent_id` INT NULL,
    `host` VARCHAR(255) NOT NULL,
    `port` VARCHAR(11) NOT NULL,
    `server_port` INT NOT NULL,
    `tags` VARCHAR(255) NULL,
    `rate` VARCHAR(11) NOT NULL,
    `show` BOOLEAN DEFAULT FALSE,
    `sort` INT NULL,
    `up_mbps` INT NOT NULL,
    `down_mbps` INT NOT NULL,
    `server_name` VARCHAR(64) NULL,
    `insecure` BOOLEAN DEFAULT FALSE,
    `created_at` INT NOT NULL,
    `updated_at` INT NOT NULL
);
SQL);
        }

        if (!Schema::hasTable('v2_server_vless')) {
            DB::statement(<<<SQL
CREATE TABLE `v2_server_vless` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `group_id` TEXT NOT NULL,
    `route_id` TEXT NULL,
    `name` VARCHAR(255) NOT NULL,
    `parent_id` INT NULL,
    `host` VARCHAR(255) NOT NULL,
    `port` INT NOT NULL,
    `server_port` INT NOT NULL,
    `tls` BOOLEAN NOT NULL,
    `tls_settings` TEXT NULL,
    `flow` VARCHAR(64) NULL,
    `network` VARCHAR(11) NOT NULL,
    `network_settings` TEXT NULL,
    `tags` TEXT NULL,
    `rate` VARCHAR(11) NOT NULL,
    `show` BOOLEAN DEFAULT FALSE,
    `sort` INT NULL,
    `created_at` INT NOT NULL,
    `updated_at` INT NOT NULL
);
SQL);
        }
    }

    protected function migrateWyx2685(): void
    {
        $this->dropColumnIfExists('v2_plan', 'device_limit');
        $this->dropColumnIfExists('v2_server_hysteria', 'version');
        $this->dropColumnIfExists('v2_server_hysteria', 'obfs');
        $this->dropColumnIfExists('v2_server_hysteria', 'obfs_password');
        $this->dropColumnIfExists('v2_server_trojan', 'network');
        $this->dropColumnIfExists('v2_server_trojan', 'network_settings');
        $this->dropColumnIfExists('v2_user', 'device_limit');
    }

    protected function renameTableIfNeeded(string $from, string $to): void
    {
        if (Schema::hasTable($from) && !Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }

    protected function renameColumnIfNeeded(string $table, string $from, string $to): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasColumn($table, $from) && !Schema::hasColumn($table, $to)) {
            Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
                $blueprint->renameColumn($from, $to);
            });
        }
    }

    protected function addColumnIfMissing(string $table, string $column, \Closure $columnDefinition): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columnDefinition): void {
            $columnDefinition($blueprint);
        });
    }

    protected function dropColumnIfExists(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }

    public function MigrateV2ConfigToV2Settings()
    {
        Artisan::call('config:clear');
        $configValue = config('v2board') ?? [];

        foreach ($configValue as $k => $v) {
            // 检查记录是否已存在
            $existingSetting = Setting::where('name', $k)->first();
            
            // 如果记录不存在，则插入
            if ($existingSetting) {
                $this->warn("配置 {$k} 在数据库已经存在， 忽略");
                continue;
            }
            Setting::create([
                'name' => $k,
                'value' => is_array($v)? json_encode($v) : $v,
            ]);
            $this->info("配置 {$k} 迁移成功");
        }
        Artisan::call('config:cache');

        $this->info('所有配置迁移完成');
    }
}
