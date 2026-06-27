<?php

declare(strict_types=1);

namespace app\service\provider;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\validate\ProviderNormalizer;

/**
 * Provider 业务编排
 *
 * 把"用户操作"翻译成对 ProviderRepository 的整体读 → 修改 → 整体写。
 * 缓存级联失效由 ProviderRepository::saveAll 内部处理，本服务专注业务校验/编排。
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
        $providers = $this->providers->rawAll();
        $definition = $this->definitionFor($data['type'] ?? '');
        $normalized = $this->normalizer->normalize($data, $definition);

        if ($this->existsId($providers, $normalized['id'])) {
            throw new ApiException('Provider already exists', 409, 'provider_exists');
        }

        $providers[] = $normalized;
        $this->providers->saveAll($providers);

        return $this->providers->present($normalized);
    }

    public function update(string $id, array $data): array
    {
        $providers = $this->providers->rawAll();
        $index = $this->locate($providers, $id);
        $current = $providers[$index];

        if (array_key_exists('type', $data) && $data['type'] !== '' && $data['type'] !== ($current['type'] ?? '')) {
            throw new ApiException('Provider type cannot be changed', 422, 'provider_type_immutable');
        }

        $merged = array_merge(
            $current,
            array_filter($data, static fn (mixed $v) => $v !== '' && $v !== null),
            ['id' => $id],
        );

        $definition = $this->definitionFor($merged['type'] ?? '');
        $providers[$index] = $this->normalizer->normalize($merged, $definition);

        $this->providers->saveAll($providers);

        return $this->providers->present($providers[$index]);
    }

    public function delete(string $id): void
    {
        $providers = $this->providers->rawAll();
        $this->checkProviderNotInUse($id, $providers);

        $next = array_values(array_filter($providers, static fn (array $p) => ($p['id'] ?? '') !== $id));
        if (count($next) === count($providers)) {
            throw new ApiException('Provider not found', 404, 'provider_not_found');
        }

        $this->providers->saveAll($next);
    }

    /**
     * 按给定 id 顺序重排（顺序即落库顺序）
     *
     * @param string[] $ids
     */
    public function sort(array $ids): array
    {
        $ids = array_values(array_map(static fn (mixed $v) => trim((string) $v), $ids));
        $providers = $this->providers->rawAll();

        $this->validateSortOrder($ids, $providers);

        $byId = $this->indexById($providers);
        $ordered = array_values(array_map(static fn (string $id) => $byId[$id], $ids));

        $this->providers->saveAll($ordered);

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
     */
    private function checkProviderNotInUse(string $id, array $providers): void
    {
        foreach ($providers as $provider) {
            $type = $provider['type'] ?? '';
            $referencedBy = match (true) {
                $type === 'edgeone' && ($provider['dnspod_provider'] ?? '') === $id => 'EdgeOne',
                $type === 'hostname' && ($provider['cloudflare_provider'] ?? '') === $id => 'Hostname',
                $type === 'hostname' && ($provider['dnspod_provider'] ?? '') === $id => 'Hostname',
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
}
