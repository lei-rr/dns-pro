<?php

declare(strict_types=1);

namespace app\repository;

use app\support\JsonStore;

/**
 * 应用配置仓储
 *
 * 统一 data/config.json 的本地持久化访问边界。
 */
class AppConfigRepository
{
    private readonly JsonStore $store;

    public function __construct(?JsonStore $store = null)
    {
        $this->store = $store ?? new JsonStore('config.json', [
            'auth' => ['username' => '', 'password' => ''],
        ]);
    }

    public function read(): array
    {
        return $this->store->read();
    }
}
