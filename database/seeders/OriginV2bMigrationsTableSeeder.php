<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class OriginV2bMigrationsTableSeeder extends Seeder
{
    protected array $migrations = [
        '2019_08_19_000000_create_failed_jobs_table',
        '2023_08_07_205816_create_v2_commission_log_table',
        '2023_08_07_205816_create_v2_coupon_table',
        '2023_08_07_205816_create_v2_invite_code_table',
        '2023_08_07_205816_create_v2_knowledge_table',
        '2023_08_07_205816_create_v2_log_table',
        '2023_08_07_205816_create_v2_mail_log_table',
        '2023_08_07_205816_create_v2_notice_table',
        '2023_08_07_205816_create_v2_order_table',
        '2023_08_07_205816_create_v2_payment_table',
        '2023_08_07_205816_create_v2_plan_table',
        '2023_08_07_205816_create_v2_server_group_table',
        '2023_08_07_205816_create_v2_server_hysteria_table',
        '2023_08_07_205816_create_v2_server_route_table',
        '2023_08_07_205816_create_v2_server_shadowsocks_table',
        '2023_08_07_205816_create_v2_server_trojan_table',
        '2023_08_07_205816_create_v2_server_vless_table',
        '2023_08_07_205816_create_v2_server_vmess_table',
        '2023_08_07_205816_create_v2_stat_server_table',
        '2023_08_07_205816_create_v2_stat_table',
        '2023_08_07_205816_create_v2_stat_user_table',
        '2023_08_07_205816_create_v2_ticket_message_table',
        '2023_08_07_205816_create_v2_ticket_table',
        '2023_08_07_205816_create_v2_user_table',
    ];

    /**
     * 原版V2b数据库迁移初始化
     *
     * @return void
     */
    public function run()
    {
        
        try{    
            \Artisan::call("migrate:install");
        }catch(\Exception $e){

        }
        $existingMigrations = \DB::table('migrations')->pluck('migration')->all();

        $rows = [];
        foreach ($this->migrations as $migration) {
            if (in_array($migration, $existingMigrations, true)) {
                continue;
            }

            $rows[] = [
                'migration' => $migration,
                'batch' => 1,
            ];
        }

        if ($rows) {
            \DB::table('migrations')->insert($rows);
        }
    }
}
