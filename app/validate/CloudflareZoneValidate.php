<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class CloudflareZoneValidate extends Validate
{
    protected $rule = [
        'page' => 'number|egt:1',
        'per_page' => 'number|between:1,100',
        'name' => 'require|string|max:253',
        'refresh' => 'boolean',
        'type' => 'string|in:full,partial',
    ];

    protected $field = [
        'page' => '页码',
        'per_page' => '每页数量',
        'name' => '域名',
        'refresh' => '刷新缓存',
        'type' => '接入类型',
    ];

    protected $scene = [
        'store' => ['name', 'type'],
    ];

    public function sceneIndex(): self
    {
        return $this->only(['page', 'per_page', 'name', 'refresh'])
            ->remove('name', 'require');
    }
}
