<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\StatServer
 *
 * @property int $id
 * @property int $server_id 服务器ID
 * @property int $u 上行流量
 * @property int $d 下行流量
 * @property int $record_at 记录时间
 * @property int $created_at
 * @property int $updated_at
 * @property-read int $value 通过SUM(u + d)计算的总流量值，仅在查询指定时可用
 */
class StatServer extends Model
{
    protected $table = 'v2_stat_server';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
