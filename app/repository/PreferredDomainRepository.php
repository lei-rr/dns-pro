<?php

declare(strict_types=1);

namespace app\repository;

use app\support\JsonStore;

/**
 * 优选域名仓储
 *
 * 统一 data/hostname/preferred-domains.json 的本地持久化访问边界。
 */
class PreferredDomainRepository
{
    private readonly JsonStore $store;

    public function __construct(?JsonStore $store = null)
    {
        $this->store = $store ?? new JsonStore('hostname/preferred-domains.json', ['items' => []]);
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
