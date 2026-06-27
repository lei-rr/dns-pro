<?php

return [
    'cache_ttl' => 259200, // 3 天

    /**
     * Provider 失效时需要清理的 cache tag 前缀清单
     *
     * tag 拼装规则：<prefix>:<providerId>
     * 这里列出的每个前缀都会在 provider 自身或其引用关系变更时被 ProviderRepository 清理。
     *
     * 新增模块时只需在此追加对应 tag prefix，无需修改 ProviderRepository。
     */
    'provider_cache_tags' => [
        'provider',
        'cloudflare:zones',
        'cloudflare:records',
        'cloudflare:custom_hostnames',
        'cloudflare:dcv_delegation',
        'cloudflare:fallback_origin',
        'cloudflare:tunnels',
        'cloudflare:tunnel_config',
        'dnspod:zones',
        'dnspod:records',
        'edgeone:zones',
        'edgeone:domains',
    ],

    'cloudflare' => [
        'base_uri' => 'https://api.cloudflare.com/client/v4/',
        'timeout' => 10,
    ],
    'tencent' => [
        'timeout' => 10,
        'dnspod_endpoint' => 'dnspod.tencentcloudapi.com',
        'edgeone_endpoint' => 'teo.tencentcloudapi.com',
        'edgeone_region' => 'ap-guangzhou',
    ],
];
