<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class CloudflareRecordValidate extends Validate
{
    protected $rule = [
        'page' => 'number|egt:1',
        'per_page' => 'number|between:1,100',
        'type' => 'require|string|max:32',
        'search' => 'string|max:255',
        'refresh' => 'boolean',
        'name' => 'require|string|max:253',
        'content' => 'require|string|max:4096',
        'ttl' => 'require|number|egt:1',
        'proxied' => 'boolean',
        'priority' => 'number|egt:0',
        'comment' => 'string|max:1024',
    ];

    protected $field = [
        'page' => '页码',
        'per_page' => '每页数量',
        'type' => '记录类型',
        'search' => '搜索关键词',
        'refresh' => '刷新缓存',
        'name' => '记录名称',
        'content' => '记录值',
        'ttl' => 'TTL',
        'proxied' => '代理状态',
        'priority' => '优先级',
        'comment' => '备注',
    ];

    protected $scene = [
        'record' => ['type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment'],
    ];

    public function sceneIndex(): self
    {
        return $this->only(['page', 'per_page', 'type', 'search', 'refresh'])
            ->remove('type', 'require');
    }
}
