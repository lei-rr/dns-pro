<?php

declare(strict_types=1);

namespace app\service\provider;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\validate\ProviderNormalizer;

/**
 * Provider 业务编排
 *
 * 把"用户操作"翻译成对 ProviderRepository 的持锁事务更新。
 * 缓存级联失效由 ProviderRepository 内部处理，本服务专注业务校验/编排。
 */
class ProviderService
{
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly ProviderNormalizer $normalizer,
    ) {
    }

    public function definitions(): array
    {
        return $this->providers->definitions();
    }

    public function all(): array
    {
        return $this->providers->all();
    }

    public function find(string $id): ?array
    {
        return $this->providers->find($id);
    }

    public function create(array $data): array
    {
        $definition = $this->definitionFor($data['type'] ?? '');
        $normalized = $this->normalizer->normalize($data, $definition);

        $this->providers->mutateAll(function (array $providers) use ($normalized): array {
            if ($this->existsId($providers, $normalized['id'])) {
                throw new ApiException('Provider already exists', 409, 'provider_exists');
            }

            $providers[] = $normalized;
            return $providers;
        });

        return $this->providers->present($normalized);
    }

    public function update(string $id, array $data): array
    {
        $updated = null;

        $this->providers->mutateAll(function (array $providers) use ($id, $data, &$updated): array {
            $index = $this->locate($providers, $id);
            $current = $providers[$index];

            if (array_key_exists('type', $data) && $data['type'] !== '' && $data['type'] !== ($current['type'] ?? '')) {
                throw new ApiException('Provider type cannot be changed', 422, 'provider_type_immutable');
            }

            $definition = $this->definitionFor((string) ($current['type'] ?? ''));
            $merged = $this->mergeUpdatePayload($current, $data, $definition);
            $updated = $this->normalizer->normalize($merged, $definition);
            $providers[$index] = $updated;

            return $providers;
        });

        return $this->providers->present($updated ?? []);
    }

    public function delete(string $id): void
    {
        $this->providers->mutateAll(function (array $providers) use ($id): array {
            $this->checkProviderNotInUse($id, $providers);

            $next = array_values(array_filter($providers, static fn (array $p) => ($p['id'] ?? '') !== $id));
            if (count($next) === count($providers)) {
                throw new ApiException('Provider not found', 404, 'provider_not_found');
            }

            return $next;
        });
    }

    /**
     * 按给定 id 顺序重排（顺序即落库顺序）
     *
     * @param string[] $ids
     */
    public function sort(array $ids): array
    {
        $ids = array_values(array_map(static fn (mixed $v) => trim((string) $v), $ids));
        $ordered = $this->providers->mutateAll(function (array $providers) use ($ids): array {
            $this->validateSortOrder($ids, $providers);

            $byId = $this->indexById($providers);
            return array_values(array_map(static fn (string $id) => $byId[$id], $ids));
        });

        return array_map(fn (array $p) => $this->providers->present($p), $ordered);
    }

    // ---------- internal ----------

    private function definitionFor(string $type): array
    {
        $definitions = $this->providers->definitionMap();
        if (!isset($definitions[$type])) {
            throw new ApiException('Invalid provider type', 422, 'validation_failed', [
                'errors' => ['type' => 'Invalid provider type'],
            ]);
        }

        return $definitions[$type];
    }

    /**
     * 检查 provider 是否被其他 provider 引用：
     *   - EdgeOne.dnspod_provider 引用了 DNSPod
     *   - Hostname.cloudflare_provider 引用了 Cloudflare
     *   - Hostname.dnspod_provider 引用了 DNSPod
     *   - Cloudflared.cloudflare_provider 引用了 Cloudflare
     */
    private function checkProviderNotInUse(string $id, array $providers): void
    {
        foreach ($providers as $provider) {
            $type = $provider['type'] ?? '';
            $referencedBy = match (true) {
                $type === 'edgeone' && ($provider['dnspod_provider'] ?? '') === $id => 'EdgeOne',
                $type === 'hostname' && ($provider['cloudflare_provider'] ?? '') === $id => 'Hostname',
                $type === 'hostname' && ($provider['dnspod_provider'] ?? '') === $id => 'Hostname',
                $type === 'cloudflared' && ($provider['cloudflare_provider'] ?? '') === $id => 'Cloudflare Tunnel',
                default => null,
            };

            if ($referencedBy !== null) {
                throw new ApiException(
                    sprintf('Provider is referenced by %s "%s"', $referencedBy, $provider['id'] ?? ''),
                    409,
                    'provider_in_use',
                    ['referenced_by' => $provider['id'] ?? ''],
                );
            }
        }
    }

    /**
     * @param string[] $ids
     */
    private function validateSortOrder(array $ids, array $providers): void
    {
        if (count($ids) !== count(array_unique($ids))) {
            throw new ApiException('Provider order contains duplicate ids', 422, 'provider_order_duplicated');
        }

        $existingIds = array_keys($this->indexById($providers));
        if (array_diff($existingIds, $ids) || array_diff($ids, $existingIds)) {
            throw new ApiException('Provider order does not match current providers', 422, 'provider_order_mismatch');
        }
    }

    private function existsId(array $providers, string $id): bool
    {
        foreach ($providers as $p) {
            if (($p['id'] ?? '') === $id) {
                return true;
            }
        }

        return false;
    }

    private function locate(array $providers, string $id): int
    {
        foreach ($providers as $index => $p) {
            if (($p['id'] ?? '') === $id) {
                return $index;
            }
        }

        throw new ApiException('Provider not found', 404, 'provider_not_found');
    }

    /**
     * @return array<string, array>
     */
    private function indexById(array $providers): array
    {
        $map = [];
        foreach ($providers as $p) {
            $map[(string) ($p['id'] ?? '')] = $p;
        }

        return $map;
    }

    private function mergeUpdatePayload(array $current, array $data, array $definition): array
    {
        $merged = $current;
        $merged['id'] = $current['id'] ?? '';
        $merged['type'] = $current['type'] ?? '';

        if (array_key_exists('name', $data)) {
            $merged['name'] = $data['name'];
        }

        $secretFields = array_fill_keys($definition['secret_fields'] ?? [], true);
        foreach ($definition['fields'] ?? [] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if ($value === null) {
                continue;
            }

            if (($secretFields[$field] ?? false) === true && $value === '') {
                continue;
            }

            $merged[$field] = $value;
        }

        return $merged;
    }
}
