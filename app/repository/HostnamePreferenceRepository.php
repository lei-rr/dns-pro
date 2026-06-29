<?php

declare(strict_types=1);

namespace app\repository;

use app\support\JsonStore;

/**
 * Hostname 偏好仓储
 *
 * 统一 data/hostname/preferences.json 的本地持久化访问边界。
 */
class HostnamePreferenceRepository
{
    private readonly JsonStore $store;

    public function __construct(?JsonStore $store = null)
    {
        $this->store = $store ?? new JsonStore('hostname/preferences.json', ['items' => []]);
    }

    public function read(): array
    {
        return $this->store->read();
    }

    public function transaction(callable $mutator): array
    {
        return $this->store->transaction($mutator);
    }
}
