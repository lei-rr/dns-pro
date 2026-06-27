<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class DnsPodZoneValidate extends Validate
{
    protected $rule = [
        'offset' => 'number|egt:0',
        'limit' => 'number|between:1,100',
        'keyword' => 'string|max:255',
        'refresh' => 'boolean',
        'domain' => 'require|string|max:253',
    ];

    protected $field = [
        'offset' => '偏移量',
        'limit' => '每页数量',
        'keyword' => '关键词',
        'refresh' => '刷新缓存',
        'domain' => '域名',
    ];

    protected $scene = [
        'index' => ['offset', 'limit', 'keyword', 'refresh'],
        'store' => ['domain'],
    ];
}
