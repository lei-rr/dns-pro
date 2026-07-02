<?php

declare(strict_types=1);

namespace app\service\saas;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\cloudflare\CloudflareCustomHostnameGateway;
use app\service\cloudflare\CloudflareZoneGateway;

/**
 * Hostname 业务服务
 *
 * 职责:
 *   - 把对外标识 (provider_id + zone_name + hostname_fqdn) 解析为 Cloudflare 内部 id (cf_provider_id + zone_id + hostname_uuid)
 *   - 把本地 preference(preferred_domain / sync_target / sync_zone 等)合并进 Cloudflare 返回的 hostname 中
 *
 * 设计:对外 API 一律用域名形式标识资源(与 dnspod/cloudflare 的 zone 路由保持一致),
 * 后端通过 list 缓存反查 UUID,不增加远程 API 调用次数。
 *
 * 注:Cloudflare custom_metadata 仅企业版可用,本服务把 preferred_domain / sync_target / sync_zone 存到本地
 * hostname_preferences 表,对前端透明地"挂"在响应的 custom_metadata.preferred_domain 字段上。
 */
class SaasService
{
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CloudflareZoneGateway $cloudflareZones,
        private readonly CloudflareCustomHostnameGateway $cloudflareHostnames,
        private readonly PreferredDomainService $preferredDomains,
        private readonly SaasPreferenceService $preferences,
    ) {
    }

    public function zones(string $providerId, int $page, int $perPage, string $name = '', bool $refresh = false): array
    {
        return $this->cloudflareZones->list(
            $this->cloudflareProviderId($providerId),
            $page,
            $perPage,
            $name,
            $refresh,
        );
    }

    public function cloudflareProviderIdFor(string $providerId): string
    {
        return $this->cloudflareProviderId($providerId);
    }

    public function hostnames(string $providerId, string $zoneName, int $page, int $perPage, bool $refresh = false): array
    {
        [$cfId, $zoneId] = $this->resolveZone($providerId, $zoneName);

        $previousStatusMap = [];
        if ($refresh) {
            try {
                $cached = $this->cloudflareHostnames->list($cfId, $zoneId, $page, $perPage, false);
                foreach ($cached['items'] ?? [] as $item) {
                    $id = (string) ($item['id'] ?? '');
                    if ($id !== '') {
                        $previousStatusMap[$id] = (string) ($item['status'] ?? '');
                    }
                }
            } catch (\Throwable) {
                // ignore: cached list may not exist yet
            }
        }

        $result = $this->cloudflareHostnames->list($cfId, $zoneId, $page, $perPage, $refresh);
        $preferenceMap = $this->preferences->listByProvider($cfId);
        $result['items'] = array_map(
            fn (array $h) => $this->applyEffectiveSyncConfig(
                $providerId,
                $this->mergePreference(
                    $refresh
                        ? $h + ['previous_status' => $previousStatusMap[(string) ($h['id'] ?? '')] ?? '']
                        : $h,
                    $preferenceMap[(string) ($h['id'] ?? '')] ?? null,
                ),
            ),
            $result['items'] ?? [],
        );

        return $result;
    }

    /**
     * 详情:默认走缓存。$refresh=true 仅刷 hostname 单条,不刷 zone id(zone 名→id 映射稳定)。
     * 附加 zone 的 DCV 委派 UUID + 本地 preference。
     */
    public function showHostname(string $providerId, string $zoneName, string $hostnameFqdn, bool $refresh = false): array
    {
        [$cfId, $zoneId, $hostnameId] = $this->resolveHostname($providerId, $zoneName, $hostnameFqdn);

        $hostname = $this->cloudflareHostnames->show($cfId, $zoneId, $hostnameId, $refresh);

        return $this->enrichDetailedHostname($providerId, $hostname, $cfId, $zoneId, $hostnameId);
    }

    /**
     * 刷新单条:强制拉最新,并失效列表缓存。
     * 返回值附加 `previous_status` 字段,供 controller 判断是否发生状态跃迁。
     */
    public function refreshHostname(string $providerId, string $zoneName, string $hostnameFqdn): array
    {
        [$cfId, $zoneId, $hostnameId] = $this->resolveHostname($providerId, $zoneName, $hostnameFqdn);

        // 先读缓存版的旧状态,失败容忍(缓存可能不存在)
        $previousStatus = '';
        try {
            $cached = $this->cloudflareHostnames->show($cfId, $zoneId, $hostnameId, false);
            $previousStatus = (string) ($cached['status'] ?? '');
        } catch (\Throwable) {
            // ignore
        }

        $hostname = $this->cloudflareHostnames->show($cfId, $zoneId, $hostnameId, true);
        $this->cloudflareHostnames->invalidate($cfId, $zoneId);

        return $this->enrichDetailedHostname($providerId, $hostname, $cfId, $zoneId, $hostnameId, $previousStatus);
    }

    public function createHostname(string $providerId, string $zoneName, array $data): array
    {
        [$cfId, $zoneId] = $this->resolveZone($providerId, $zoneName);
        $preferred = $this->extractPreferredDomain($data);

        $hostname = $this->cloudflareHostnames->create($cfId, $zoneId, $data);
        $hostnameId = (string) ($hostname['id'] ?? '');

        if ($hostnameId !== '' && $preferred !== null) {
            $this->preferences->setPreferredDomain($cfId, $hostnameId, $preferred);
        }

        if ($hostnameId !== '' && (array_key_exists('sync_target', $data) || array_key_exists('sync_zone', $data))) {
            $this->preferences->setSyncConfig(
                $cfId,
                $hostnameId,
                (string) ($data['sync_target'] ?? ''),
                (string) ($data['sync_provider_id'] ?? ''),
                (string) ($data['sync_zone'] ?? ''),
                (bool) ($data['auto_preferred'] ?? false),
                (string) ($hostname['hostname'] ?? ''),
            );
        }

        if ($hostnameId !== '') {
            $this->preferences->markOwnershipTxtCleaned($cfId, $hostnameId, false, (string) ($hostname['hostname'] ?? ''));
        }

        return $this->withPreference($hostname, $cfId, $hostnameId);
    }

    public function updateHostname(string $providerId, string $zoneName, string $hostnameFqdn, array $data): array
    {
        [$cfId, $zoneId, $hostnameId] = $this->resolveHostname($providerId, $zoneName, $hostnameFqdn);
        $preferred = array_key_exists('preferred_domain', $data) ? $this->extractPreferredDomain($data) : null;

        $hostname = $this->cloudflareHostnames->update($cfId, $zoneId, $hostnameId, $data);

        if ($preferred !== null) {
            $this->preferences->setPreferredDomain($cfId, $hostnameId, $preferred);
        }

        if (array_key_exists('sync_target', $data) || array_key_exists('sync_zone', $data)) {
            $this->preferences->setSyncConfig(
                $cfId,
                $hostnameId,
                (string) ($data['sync_target'] ?? ''),
                (string) ($data['sync_provider_id'] ?? ''),
                (string) ($data['sync_zone'] ?? ''),
                (bool) ($data['auto_preferred'] ?? false),
                (string) ($hostname['hostname'] ?? $hostnameFqdn),
            );
        }

        $this->preferences->markOwnershipTxtCleaned($cfId, $hostnameId, false, (string) ($hostname['hostname'] ?? $hostnameFqdn));

        return $this->withPreference($hostname, $cfId, $hostnameId);
    }

    public function deleteHostname(string $providerId, string $zoneName, string $hostnameFqdn): array
    {
        [$cfId, $zoneId, $hostnameId] = $this->resolveHostname($providerId, $zoneName, $hostnameFqdn);

        $result = $this->cloudflareHostnames->delete($cfId, $zoneId, $hostnameId);
        $this->preferences->clear($cfId, $hostnameId);

        return $result;
    }

    /**
     * 获取 zone fallback origin 的完整信息
     */
    public function fallbackOriginInfo(string $providerId, string $zoneName, bool $refresh = false): array
    {
        [$cfId, $zoneId] = $this->resolveZone($providerId, $zoneName);

        return $this->cloudflareHostnames->fallbackOriginInfo($cfId, $zoneId, $refresh);
    }

    public function setFallbackOrigin(string $providerId, string $zoneName, string $origin): array
    {
        [$cfId, $zoneId] = $this->resolveZone($providerId, $zoneName);
        $normalized = $this->normalizeFallbackOrigin($zoneName, $origin);

        return $this->cloudflareHostnames->setFallbackOrigin($cfId, $zoneId, $normalized);
    }

    public function deleteFallbackOrigin(string $providerId, string $zoneName): array
    {
        [$cfId, $zoneId] = $this->resolveZone($providerId, $zoneName);

        return $this->cloudflareHostnames->deleteFallbackOrigin($cfId, $zoneId);
    }

    /**
     * 仅取 fallback origin 字符串值（SaasSyncService 内部用）
     */
    public function fallbackOrigin(string $providerId, string $zoneName): ?string
    {
        return $this->fallbackOriginInfo($providerId, $zoneName)['origin'] ?? null;
    }

    /**
     * 解析 saas provider → [cloudflare provider id, cloudflare zone id]
     */
    private function resolveZone(string $providerId, string $zoneName): array
    {
        $cfId = $this->cloudflareProviderId($providerId);

        return [$cfId, $this->cloudflareZones->idByName($cfId, $zoneName)];
    }

    /**
     * 解析 (provider + zone + hostnameFqdn) → [cloudflare provider id, zone id, hostname uuid]
     */
    private function resolveHostname(string $providerId, string $zoneName, string $hostnameFqdn): array
    {
        [$cfId, $zoneId] = $this->resolveZone($providerId, $zoneName);
        $hostnameId = $this->cloudflareHostnames->idByHostname($cfId, $zoneId, $hostnameFqdn);

        return [$cfId, $zoneId, $hostnameId];
    }

    /**
     * 取出本地字段并校验，同时从 $data 中删除这些 key 防止误传给 Cloudflare
     *
     * @return string|null null=入参未传该字段;''=显式清空;非空=校验通过的域名
     */
    private function extractPreferredDomain(array &$data): ?string
    {
        if (!array_key_exists('preferred_domain', $data)) {
            return null;
        }
        $preferred = trim((string) $data['preferred_domain']);
        unset($data['preferred_domain']);

        if ($preferred !== '' && !$this->preferredDomains->isAllowed($preferred)) {
            throw new ApiException(
                sprintf('Preferred domain "%s" is not in your preferred domains list', $preferred),
                422,
                'preferred_domain_not_allowed',
                ['preferred_domain' => $preferred],
            );
        }

        return $preferred;
    }

    public function syncConfig(string $providerId, string $hostnameFqdn, string $zoneName = ''): array
    {
        [$cfId, , $hostnameId] = $zoneName !== ''
            ? $this->resolveHostname($providerId, $zoneName, $hostnameFqdn)
            : $this->resolveHostnameByFqdn($providerId, $hostnameFqdn);

        $preference = $this->preferences->get($cfId, $hostnameId) ?? [];

        return [
            'hostname' => (string) ($preference['hostname'] ?? ''),
            'sync_target' => (string) ($preference['sync_target'] ?? ''),
            'sync_provider_id' => (string) ($preference['sync_provider_id'] ?? ''),
            'sync_zone' => (string) ($preference['sync_zone'] ?? ''),
            'auto_preferred' => (bool) ($preference['auto_preferred'] ?? false),
        ];
    }

    public function effectiveSyncConfig(string $providerId, string $hostnameFqdn, string $zoneName = ''): array
    {
        $explicit = $this->syncConfig($providerId, $hostnameFqdn, $zoneName);

        $target = trim((string) ($explicit['sync_target'] ?? ''));
        if ($target === '') {
            $target = $this->defaultSyncTarget($providerId);
        }

        return [
            'hostname' => (string) ($explicit['hostname'] ?? ''),
            'sync_target' => $target,
            'sync_provider_id' => $this->effectiveSyncProviderId($providerId, $target, (string) ($explicit['sync_provider_id'] ?? '')),
            'sync_zone' => (string) ($explicit['sync_zone'] ?? ''),
            'auto_preferred' => (bool) ($explicit['auto_preferred'] ?? false),
            'explicit' => trim((string) ($explicit['sync_target'] ?? '')) !== '' || trim((string) ($explicit['sync_provider_id'] ?? '')) !== '' || trim((string) ($explicit['sync_zone'] ?? '')) !== '',
        ];
    }

    public function defaultSyncTarget(string $providerId): string
    {
        $provider = $this->providers->requireType(
            $providerId,
            'saas',
            'SaaS provider not found',
            'saas_provider_not_found',
        );

        if (trim((string) ($provider['dnspod_provider'] ?? '')) !== '') {
            return 'dnspod';
        }

        if (trim((string) ($provider['cloudflare_dns_provider'] ?? '')) !== '' || trim((string) ($provider['cloudflare_provider'] ?? '')) !== '') {
            return 'cloudflare_dns';
        }

        return '';
    }

    /**
     * 把本地 preference 合并到 hostname 响应中
     */
    private function mergePreference(array $hostname, ?array $preference): array
    {
        $metadata = is_array($hostname['custom_metadata'] ?? null) ? $hostname['custom_metadata'] : [];
        $preferred = trim((string) ($preference['preferred_domain'] ?? ''));
        $syncTarget = trim((string) ($preference['sync_target'] ?? ''));
        $syncProviderId = trim((string) ($preference['sync_provider_id'] ?? ''));
        $syncZone = trim((string) ($preference['sync_zone'] ?? ''));
        $autoPreferred = (bool) ($preference['auto_preferred'] ?? false);

        if ($preferred !== '') {
            $metadata['preferred_domain'] = $preferred;
        } else {
            unset($metadata['preferred_domain']);
        }

        $hostname['custom_metadata'] = $metadata === [] ? null : $metadata;
        $hostname['sync_target'] = $syncTarget;
        $hostname['sync_provider_id'] = $syncProviderId;
        $hostname['sync_zone'] = $syncZone;
        $hostname['auto_preferred'] = $autoPreferred;

        return $hostname;
    }

    private function withPreference(array $hostname, string $cfId, string $hostnameId): array
    {
        return $this->mergePreference(
            $hostname,
            $hostnameId !== '' ? $this->preferences->get($cfId, $hostnameId) : null,
        );
    }

    private function enrichDetailedHostname(string $providerId, array $hostname, string $cfId, string $zoneId, string $hostnameId, string $previousStatus = ''): array
    {
        $ssl = is_array($hostname['ssl'] ?? null) ? $hostname['ssl'] : [];
        $hostname['ssl'] = $ssl;
        $hostname['ssl']['dcv_delegation_uuid'] = ($ssl['dcv_delegation_uuid'] ?? '')
            ?: $this->cloudflareZones->dcvDelegationUuid($cfId, $zoneId);

        if ($previousStatus !== '') {
            $hostname['previous_status'] = $previousStatus;
        }

        return $this->applyEffectiveSyncConfig($providerId, $this->withPreference($hostname, $cfId, $hostnameId));
    }

    private function applyEffectiveSyncConfig(string $providerId, array $hostname): array
    {
        $effective = $this->effectiveSyncConfig($providerId, (string) ($hostname['hostname'] ?? ''));
        $hostname['effective_sync_target'] = $effective['sync_target'];
        $hostname['effective_sync_provider_id'] = $effective['sync_provider_id'];
        $hostname['effective_sync_zone'] = $effective['sync_zone'];
        $hostname['sync_config_explicit'] = (bool) ($effective['explicit'] ?? false);

        return $hostname;
    }

    private function effectiveSyncProviderId(string $providerId, string $target, string $explicitProviderId): string
    {
        $explicitProviderId = trim($explicitProviderId);
        if ($explicitProviderId !== '') {
            return $explicitProviderId;
        }

        $provider = $this->providers->requireType(
            $providerId,
            'saas',
            'SaaS provider not found',
            'saas_provider_not_found',
        );

        if ($target === 'dnspod') {
            return trim((string) ($provider['dnspod_provider'] ?? ''));
        }

        if ($target === 'cloudflare_dns') {
            $cloudflareDnsProvider = trim((string) ($provider['cloudflare_dns_provider'] ?? ''));
            if ($cloudflareDnsProvider !== '') {
                return $cloudflareDnsProvider;
            }

            return trim((string) ($provider['cloudflare_provider'] ?? ''));
        }

        return '';
    }

    private function resolveHostnameByFqdn(string $providerId, string $hostnameFqdn): array
    {
        $provider = $this->providers->requireType(
            $providerId,
            'saas',
            'SaaS provider not found',
            'saas_provider_not_found',
        );
        $cfId = $this->cloudflareProviderId($providerId);
        $fqdn = strtolower(trim($hostnameFqdn));

        foreach ($this->zones($providerId, 1, 1000, '', false)['items'] ?? [] as $zone) {
            $zoneName = trim((string) ($zone['name'] ?? ''));
            if ($zoneName === '') {
                continue;
            }

            try {
                [$resolvedCfId, $zoneId, $hostnameId] = $this->resolveHostname($providerId, $zoneName, $fqdn);
                if ($resolvedCfId === $cfId) {
                    return [$resolvedCfId, $zoneId, $hostnameId];
                }
            } catch (ApiException) {
                continue;
            }
        }

        throw new ApiException('SaaS hostname not found', 404, 'saas_hostname_not_found', [
            'hostname' => $hostnameFqdn,
            'provider_id' => $provider['id'] ?? $providerId,
        ]);
    }

    /**
     * 校验 + 归一化 fallback origin:
     *   - 必须是该 zone 的子域名(以 .<zone> 结尾,且不能等于 zone 自身)
     *   - 转小写,去末尾点
     * Cloudflare API 不强制校验这点,但 SSL 签发依赖此约束,所以前置守卫。
     */
    private function normalizeFallbackOrigin(string $zoneName, string $origin): string
    {
        $zone = strtolower(rtrim(trim($zoneName), '.'));
        $value = strtolower(rtrim(trim($origin), '.'));

        if ($value === '' || $value === $zone || !str_ends_with($value, '.' . $zone)) {
            throw new ApiException(
                sprintf('Fallback origin must be a subdomain of %s', $zone),
                422,
                'fallback_origin_zone_mismatch',
                ['zone' => $zone, 'origin' => $origin],
            );
        }

        return $value;
    }

    private function cloudflareProviderId(string $providerId): string
    {
        $provider = $this->providers->requireType(
            $providerId,
            'saas',
            'SaaS provider not found',
            'saas_provider_not_found',
        );

        $cfId = trim((string) ($provider['cloudflare_provider'] ?? ''));
        if ($cfId === '') {
            throw new ApiException(
                'SaaS provider is not linked to a Cloudflare provider',
                422,
                'saas_cloudflare_provider_missing',
                ['provider_id' => $providerId],
            );
        }

        return $cfId;
    }
}
