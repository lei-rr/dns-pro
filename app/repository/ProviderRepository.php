<?php

declare(strict_types=1);

namespace app\repository;

use app\exception\ApiException;
use app\support\JsonStore;
use RuntimeException;
use think\facade\Cache;

/**
 * Provider 数据访问层
 *
 * 单一存储后端：data/providers.json，结构 {"items": [{type, id, name, ...}]}。
 * 数组顺序即显示顺序；id 在所有 type 下全局唯一。
 *
 * 职责：
 *   - 读：rawAll / find / all / requireType
 *   - 写：mutateAll / saveAll（整体覆盖）→ 自动失效自身及级联引用方的 API 缓存
 *   - 呈现：present / all 根据 config/providers.php 隐藏 secret、计算 configured/fields/editable_fields
 *
 * 跨 provider 引用关系（被引用 → 引用方字段）：
 *   dnspod     ← edgeone.dnspod_provider
 *   dnspod     ← hostname.dnspod_provider
 *   cloudflare ← hostname.cloudflare_provider
 *   cloudflare ← cloudflared.cloudflare_provider
 */
class ProviderRepository
{
    private const TYPE_ALIASES = [
        'hostname' => 'saas',
    ];

    /**
     * 反向引用规则：[被引用类型 => [[引用方字段, 引用方期望类型?]]]
     * 引用方期望类型 null 表示任意类型只要拿该字段引用就匹配。
     *
     * @var array<string, array<int, array{0:string, 1:string|null}>>
     */
    private const INCOMING_REFERENCES = [
        'dnspod' => [
            ['dnspod_provider', null],
        ],
        'cloudflare' => [
            ['cloudflare_provider', null],
            ['cloudflare_dns_provider', null],
        ],
    ];

    private readonly JsonStore $store;

    public function __construct(?JsonStore $store = null)
    {
        $this->store = $store ?? new JsonStore('providers.json', ['items' => []]);
    }

    // ---------- definitions ----------

    public function definitions(): array
    {
        return array_values($this->definitionMap());
    }

    public function definitionMap(): array
    {
        $definitions = config('providers.definitions', []);
        if (!is_array($definitions)) {
            throw new RuntimeException('Invalid provider definitions config.');
        }

        return $definitions;
    }

    // ---------- read ----------

    /**
     * 全部 provider（含 secret），按存储顺序返回
     */
    public function rawAll(): array
    {
        $items = $this->store->read()['items'] ?? [];
        $providers = is_array($items) ? array_values($items) : [];
        $migrated = array_map(fn (array $provider) => $this->migrateProviderType($provider), $providers);

        if ($providers !== $migrated) {
            $this->writeMigrated($migrated);
        }

        return $migrated;
    }

    public function all(bool $includeSecrets = false): array
    {
        return array_map(
            fn (array $provider) => $this->present($provider, $includeSecrets),
            $this->rawAll(),
        );
    }

    public function find(string $id, bool $includeSecrets = false): ?array
    {
        foreach ($this->rawAll() as $provider) {
            if (($provider['id'] ?? '') === $id) {
                return $this->present($provider, $includeSecrets);
            }
        }

        return null;
    }

    /**
     * 取出指定类型且配置完整的 provider，否则抛 ApiException
     *
     * 由各 service 在调用远程 API 前用作"前置守卫"。
     */
    public function requireType(string $id, string $type, ?string $message = null, ?string $code = null): array
    {
        $provider = $this->find($id, true);

        if (!$provider || ($provider['type'] ?? '') !== $type) {
            throw new ApiException($message ?? 'Provider not found', 404, $code ?? 'provider_not_found');
        }

        $definition = $this->definitionMap()[$type] ?? null;
        if ($definition === null || !$this->isConfigured($provider, $definition)) {
            throw new ApiException('Provider is not configured', 422, 'provider_not_configured');
        }

        return $provider;
    }

    // ---------- write ----------

    /**
     * 整体覆盖写入。
     *
     * 写入成功后自动失效缓存：
     *   - 受影响的 provider 自身（创建 / 修改 / 删除都算）
     *   - 引用了它们的下游 provider（如 dnspod 变了，所有引用它的 edgeone/hostname 也清）
     */
    public function saveAll(array $providers): void
    {
        $this->mutateAll(static fn (array $_current): array => $providers);
    }

    /**
     * 在同一把文件锁内完成 provider 列表的读改写。
     *
     * 重要约束：闭包内部不要再调用本仓储的 present()/all()/find()/rawAll() 等方法，
     * 否则容易在持锁期间再次回读 providers.json，造成锁重入阻塞。
     * 闭包内的校验/依赖判断应尽量只基于传入的当前 providers 数组完成。
     *
     * @param callable(array): array $mutator 接收当前 providers，返回新的 providers 列表
     * @return array<int, array<string, mixed>> 写入后的 provider 列表
     */
    public function mutateAll(callable $mutator): array
    {
        $before = [];
        $after = [];

        $this->store->transaction(function (array $current) use ($mutator, &$before, &$after): array {
            $items = $current['items'] ?? [];
            $before = is_array($items) ? array_values($items) : [];

            $next = $mutator($before);
            if (!is_array($next)) {
                throw new RuntimeException('Provider mutator must return an array.');
            }

            $after = array_values(array_map(fn (array $p) => $this->normalizeForStorage($p), $next));

            return ['items' => $after];
        });

        $this->invalidateAffected($before, $after);

        return $after;
    }

    // ---------- presentation ----------

    /**
     * 标准化对外呈现：根据 definition 隐藏 secret 字段，计算 configured / fields / editable_fields。
     *
     * $includeSecrets=true 时直接返回原数据，供 service 内部取凭据使用。
     */
    public function present(array $provider, bool $includeSecrets = false): array
    {
        $definition = $this->definitionMap()[$provider['type'] ?? ''] ?? null;
        if ($definition === null || $includeSecrets) {
            return $provider;
        }

        $provider['name'] = $this->displayName($provider, $definition);
        $configured = $this->isConfigured($provider, $definition);
        $hidden = $this->hideSecretFields($provider, $definition);

        return $this->withPresentationFields($hidden, $definition, $configured);
    }

    // ---------- configured / 引用判定 ----------

    private function isConfigured(array $provider, array $definition): bool
    {
        foreach ($definition['required'] as $field) {
            if (($provider[$field] ?? '') === '') {
                return false;
            }
        }

        return $this->isLinkedProviderConfigured($provider);
    }

    /**
     * 引用方需要确认被引用 provider 自身 required 完整：
     *   - edgeone:     dnspod_provider 必填
     *   - hostname:    cloudflare_provider 必填、dnspod_provider / cloudflare_dns_provider 选填（填了就要 configured）
     *   - cloudflared: cloudflare_provider 必填
     */
    private function isLinkedProviderConfigured(array $provider): bool
    {
        $type = $provider['type'] ?? '';

        if ($type === 'edgeone') {
            return $this->refConfigured((string) ($provider['dnspod_provider'] ?? ''), 'dnspod');
        }

        if ($type === 'saas') {
            if (!$this->refConfigured((string) ($provider['cloudflare_provider'] ?? ''), 'cloudflare')) {
                return false;
            }
            $dnspod = (string) ($provider['dnspod_provider'] ?? '');
            if ($dnspod !== '' && !$this->refConfigured($dnspod, 'dnspod')) {
                return false;
            }

            $cloudflareDns = (string) ($provider['cloudflare_dns_provider'] ?? '');
            return $cloudflareDns === '' || $this->refConfigured($cloudflareDns, 'cloudflare');
        }

        if ($type === 'cloudflared') {
            return $this->refConfigured((string) ($provider['cloudflare_provider'] ?? ''), 'cloudflare');
        }

        return true;
    }

    private function refConfigured(string $refId, string $expectedType): bool
    {
        if ($refId === '') {
            return false;
        }

        $definition = $this->definitionMap()[$expectedType] ?? null;
        if ($definition === null) {
            return false;
        }

        foreach ($this->rawAll() as $candidate) {
            if (($candidate['id'] ?? '') !== $refId || ($candidate['type'] ?? '') !== $expectedType) {
                continue;
            }
            foreach ($definition['required'] as $field) {
                if (($candidate[$field] ?? '') === '') {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    // ---------- presentation 内部 ----------

    private function hideSecretFields(array $provider, array $definition): array
    {
        foreach ($definition['secret_fields'] as $field) {
            $hasValue = ($provider[$field] ?? '') !== '';
            $provider[$field] = null;
            $provider[$field . '_configured'] = $hasValue;
        }

        return $provider;
    }

    private function withPresentationFields(array $provider, array $definition, bool $configured): array
    {
        $provider['editable_fields'] = $definition['fields'];
        $provider['fields'] = [];
        $provider['configured'] = $configured;

        foreach ($definition['fields'] as $field) {
            $hasValue = ($provider[$field] ?? '') !== ''
                || ($provider[$field . '_configured'] ?? false) === true;
            $provider['fields'][$field] = $hasValue ? (($provider[$field] ?? '') ?: '已配置') : '';
        }

        return $provider;
    }

    private function displayName(array $provider, array $definition): string
    {
        $name = trim((string) ($provider['name'] ?? ''));
        return $name !== '' ? $name : (string) ($definition['name'] ?? '');
    }

    /**
     * 写盘前归一：type/id/name 优先，按 definition.fields 输出已声明字段；
     * 未在 definition 中声明的额外字段保留透传，便于将来扩展不破坏旧数据。
     */
    private function normalizeForStorage(array $provider): array
    {
        $type = $this->normalizedType((string) ($provider['type'] ?? ''));
        $definition = $this->definitionMap()[$type] ?? null;

        $head = [
            'type' => $type,
            'id' => (string) ($provider['id'] ?? ''),
            'name' => (string) ($provider['name'] ?? ''),
        ];

        $body = [];
        if ($definition !== null) {
            foreach ($definition['fields'] as $field) {
                $body[$field] = (string) ($provider[$field] ?? '');
            }
        }

        // 透传未声明的额外字段（保留原值，不强制 string 化以防破坏结构）
        $declared = array_merge(array_keys($head), array_keys($body));
        $extras = array_diff_key($provider, array_flip($declared));

        return $head + $body + $extras;
    }

    private function normalizedType(string $type): string
    {
        return self::TYPE_ALIASES[$type] ?? $type;
    }

    private function migrateProviderType(array $provider): array
    {
        if (isset($provider['type'])) {
            $provider['type'] = $this->normalizedType((string) $provider['type']);
        }

        return $provider;
    }

    private function writeMigrated(array $providers): void
    {
        $this->store->write([
            'items' => array_values(array_map(fn (array $provider) => $this->normalizeForStorage($provider), $providers)),
        ]);
    }

    // ---------- cache invalidation ----------

    /**
     * 清理 before/after 差异中所有 provider 的缓存，并级联清理引用方
     *
     * 差异定义：在 before 但不在 after（删除）/ 在 after 但不在 before（新增）/ 同 id 但内容变了（更新）
     */
    private function invalidateAffected(array $before, array $after): void
    {
        $beforeMap = [];
        foreach ($before as $p) {
            $beforeMap[(string) ($p['id'] ?? '')] = $p;
        }

        $touched = [];
        foreach ($after as $p) {
            $id = (string) ($p['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!isset($beforeMap[$id]) || $beforeMap[$id] !== $p) {
                $touched[$id] = true;
            }
            unset($beforeMap[$id]);
        }
        // 剩下的就是被删除的
        foreach ($beforeMap as $id => $_) {
            if ($id !== '') {
                $touched[(string) $id] = true;
            }
        }

        foreach (array_keys($touched) as $id) {
            $this->clearProviderTags($id);
            foreach ($this->dependents($id, $before, $after) as $depId) {
                $this->clearProviderTags($depId);
            }
        }
    }

    /**
     * 找出所有引用了 $providerId 的下游 provider id（在 before 或 after 中均检查）
     *
     * @return string[]
     */
    private function dependents(string $providerId, array $before, array $after): array
    {
        $allRows = array_merge($before, $after);
        $referencedType = null;
        foreach ($allRows as $row) {
            if (($row['id'] ?? '') === $providerId) {
                $referencedType = (string) ($row['type'] ?? '');
                break;
            }
        }
        if ($referencedType === null) {
            return [];
        }

        $rules = self::INCOMING_REFERENCES[$referencedType] ?? [];
        if ($rules === []) {
            return [];
        }

        $deps = [];
        foreach ($allRows as $row) {
            foreach ($rules as [$field, $expectedRefType]) {
                if (($row[$field] ?? '') !== $providerId) {
                    continue;
                }
                if ($expectedRefType !== null && ($row['type'] ?? '') !== $expectedRefType) {
                    continue;
                }
                $deps[(string) ($row['id'] ?? '')] = true;
            }
        }

        unset($deps[$providerId]);
        return array_values(array_filter(array_keys($deps), fn ($id) => $id !== ''));
    }

    private function clearProviderTags(string $providerId): void
    {
        // tag 前缀清单来自 config/services.php，新模块只需追加即可
        $prefixes = (array) config('services.provider_cache_tags', []);
        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                Cache::tag($prefix . ':' . $providerId)->clear();
            }
        }
    }
}
