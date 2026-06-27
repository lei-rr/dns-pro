<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class CloudflaredValidate extends Validate
{
    protected $rule = [
        'name' => 'require|string|max:128',
        'hostname' => 'require|string|max:253',
        'service' => 'require|string|max:253',
        'protocol' => 'string|in:http,https,tcp,ssh,rdp,smb',
        'address' => 'require|string|max:253',
        'zone_id' => 'require|string|max:64',
        'path' => 'string|max:253',
        'refresh' => 'boolean',
    ];

    protected $field = [
        'name' => '隧道名称',
        'hostname' => '公共主机名',
        'service' => '服务地址',
        'protocol' => '协议',
        'address' => '目标地址',
        'zone_id' => 'Zone ID',
        'path' => '路径',
    ];

    protected $scene = [
        'create' => ['name'],
        'route' => ['hostname', 'protocol', 'address', 'zone_id', 'path'],
    ];
}
