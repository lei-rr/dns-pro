<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class EdgeOneRecordValidate extends Validate
{
    protected $rule = [
        'offset' => 'number|egt:0',
        'limit' => 'number|between:1,200',
        'refresh' => 'boolean',
        'status' => 'require|in:online,offline',
        'https_mode' => 'require|in:disable,eofreecert,sslcert',
        'cert_id' => 'string|max:128',
        'origin_type' => 'string|in:IP_DOMAIN,COS,AWS_S3,ORIGIN_GROUP,VOD',
        'origin' => 'require|string|max:253',
        'host_header' => 'string|max:253',
        'origin_protocol' => 'string|in:FOLLOW,HTTP,HTTPS',
        'http_origin_port' => 'number|between:1,65535',
        'https_origin_port' => 'number|between:1,65535',
        'ipv6_status' => 'string|in:follow,on,off',
        'domain_name' => 'require|string|max:253',
    ];

    protected $field = [
        'offset' => '偏移量',
        'limit' => '每页数量',
        'refresh' => '刷新缓存',
        'status' => '状态',
        'https_mode' => 'HTTPS模式',
        'cert_id' => '证书ID',
        'origin_type' => '源站类型',
        'origin' => '源站地址',
        'host_header' => '回源Host',
        'origin_protocol' => '回源协议',
        'http_origin_port' => 'HTTP回源端口',
        'https_origin_port' => 'HTTPS回源端口',
        'ipv6_status' => 'IPv6状态',
        'domain_name' => '加速域名',
    ];

    protected $scene = [
        'index' => ['offset', 'limit', 'refresh'],
        'status' => ['status'],
        'certificate' => ['https_mode', 'cert_id'],
        'record' => ['origin_type', 'origin', 'host_header', 'origin_protocol', 'http_origin_port', 'https_origin_port', 'ipv6_status', 'domain_name'],
    ];

    public function sceneUpdateRecord(): self
    {
        return $this->only(['origin_type', 'origin', 'host_header', 'origin_protocol', 'http_origin_port', 'https_origin_port', 'ipv6_status'])
            ->remove('domain_name', 'require');
    }
}
