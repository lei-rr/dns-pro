<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

/**
 * Hostname 模块入参校验
 *
 * Scenes:
 *   - listZones        GET  /zones                       page/per_page/name/refresh
 *   - listHostnames    GET  /hostnames                   page/per_page/refresh
 *   - show             GET  /hostnames/:id               refresh
 *                      GET  /fallback-origin             refresh
 *   - store            POST /hostnames                   hostname / method / min_tls_version / custom_origin_server
 *   - update           PUT  /hostnames/:id               method / min_tls_version / custom_origin_server / preferred_domain
 *   - fallbackOrigin   PUT  /fallback-origin             origin
 */
class HostnameValidate extends Validate
{
    protected $rule = [
        'page' => 'number|egt:1',
        'per_page' => 'number|between:1,100',
        'name' => 'string|max:253',
        'refresh' => 'boolean',
        'hostname' => 'require|string|max:253',
        'custom_origin_server' => 'string|max:253',
        'method' => 'string|in:http,txt',
        'min_tls_version' => 'string|in:1.0,1.1,1.2,1.3',
        'origin' => 'require|string|max:253',
        'preferred_domain' => 'string|max:253',
    ];

    protected $field = [
        'page' => '页码',
        'per_page' => '每页数量',
        'name' => '站点名称',
        'refresh' => '刷新缓存',
        'hostname' => '主机名',
        'custom_origin_server' => '回源地址',
        'method' => '验证方式',
        'min_tls_version' => '最低 TLS 版本',
        'origin' => '默认回源域名',
        'preferred_domain' => '优选域名',
    ];

    public function sceneListZones(): self
    {
        return $this->only(['page', 'per_page', 'name', 'refresh']);
    }

    public function sceneListHostnames(): self
    {
        return $this->only(['page', 'per_page', 'refresh']);
    }

    public function sceneShow(): self
    {
        return $this->only(['refresh']);
    }

    public function sceneStore(): self
    {
        return $this->only(['hostname', 'custom_origin_server', 'method', 'min_tls_version', 'preferred_domain'])
            ->remove('custom_origin_server', 'require')
            ->remove('method', 'require')
            ->remove('min_tls_version', 'require');
    }

    public function sceneUpdate(): self
    {
        return $this->only(['custom_origin_server', 'method', 'min_tls_version', 'preferred_domain'])
            ->remove('custom_origin_server', 'require')
            ->remove('method', 'require')
            ->remove('min_tls_version', 'require');
    }

    public function sceneFallbackOrigin(): self
    {
        return $this->only(['origin']);
    }
}
