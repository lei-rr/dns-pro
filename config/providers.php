<?php

return [
    'definitions' => [
        'dnspod' => [
            'type' => 'dnspod',
            'name' => 'DNSPod',
            'fields' => ['secret_id', 'secret_key'],
            'required' => ['secret_id', 'secret_key'],
            'secret_fields' => ['secret_key'],
            'labels' => [
                'secret_id' => 'SecretId',
                'secret_key' => 'SecretKey',
            ],
        ],
        'cloudflare' => [
            'type' => 'cloudflare',
            'name' => 'Cloudflare',
            'fields' => ['api_token', 'account_id'],
            'required' => ['api_token'],
            'secret_fields' => ['api_token'],
            'labels' => [
                'api_token' => 'API Token',
                'account_id' => 'Account ID',
            ],
        ],
        'edgeone' => [
            'type' => 'edgeone',
            'name' => 'EdgeOne',
            'fields' => ['dnspod_provider'],
            'required' => ['dnspod_provider'],
            'secret_fields' => [],
            'labels' => [
                'dnspod_provider' => '关联 DNSPod API',
            ],
        ],
        'hostname' => [
            'type' => 'hostname',
            'name' => 'Hostname',
            'fields' => ['cloudflare_provider', 'dnspod_provider'],
            'required' => ['cloudflare_provider'],
            'secret_fields' => [],
            'labels' => [
                'cloudflare_provider' => '关联 Cloudflare API',
                'dnspod_provider' => '关联 DNSPod API',
            ],
        ],
    ],
];
