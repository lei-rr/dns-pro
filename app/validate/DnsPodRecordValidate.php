<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class DnsPodRecordValidate extends Validate
{
    protected $rule = [
        'offset' => 'number|egt:0',
        'limit' => 'number|between:1,100',
        'subdomain' => 'string|max:255',
        'record_type' => 'require|string|max:32',
        'keyword' => 'string|max:255',
        'refresh' => 'boolean',
        'record_line' => 'require|string|max:64',
        'value' => 'require|string|max:4096',
        'record_line_id' => 'string|max:64',
        'mx' => 'number|between:0,65535',
        'ttl' => 'number|between:1,604800',
        'weight' => 'number|between:0,100',
        'status' => 'in:ENABLE,DISABLE',
        'remark' => 'string|max:255',
    ];

    protected $field = [
        'offset' => '偏移量',
        'limit' => '每页数量',
        'subdomain' => '主机记录',
        'record_type' => '记录类型',
        'keyword' => '关键词',
        'refresh' => '刷新缓存',
        'record_line' => '记录线路',
        'value' => '记录值',
        'record_line_id' => '线路ID',
        'mx' => 'MX优先级',
        'ttl' => 'TTL',
        'weight' => '权重',
        'status' => '记录状态',
        'remark' => '备注',
    ];

    protected $scene = [
        'record' => ['record_type', 'record_line', 'value', 'subdomain', 'record_line_id', 'mx', 'ttl', 'weight', 'status', 'remark'],
    ];

    public function sceneIndex(): self
    {
        return $this->only(['offset', 'limit', 'subdomain', 'record_type', 'keyword', 'refresh'])
            ->remove('record_type', 'require');
    }
}
