<?php

declare(strict_types=1);

namespace app\service\dnspod;

use app\exception\ApiException;
use app\repository\ProviderRepository;

/**
 * DNSPod 同步支撑服务
 *
 * 业务模块（saas / edgeone / ...）在与 DNSPod 联动时反复需要：
 *   - 从某业务 provider 找出关联的 DNSPod provider id
 *   - 把 FQDN 按最长后缀匹配落到具体的 DNSPod zone（zone 不一定等于业务 zone）
 *   - 同步前清理与 CNAME 冲突的同名旧记录（A/AAAA/MX/NS 等）
 *
 * 本服务**属于 dnspod 模块**，被各业务模块单向依赖；不引用任何业务模块代码，
 * 避免出现 saas → edgeone 这类横向交叉引用。
 *
 * 调用方按需在自己的 SyncService 构造函数注入本服务，并保留业务专属的
 * 记录收集 / 删除策略（如 saas 的多记录拼装、edgeone 的 CNAME 模糊清理）。
 */
class DnsPodSyncSupport
{
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly DnsPodZoneGateway $zones,
        private readonly DnsPodRecordGateway $records,
        private readonly DnsPodRecordSync $recordSync,
    ) {
    }

    /**
     * 查找业务 provider 关联的 DNSPod provider id；未关联返回空串
     *
     * @param string $providerId    业务 provider id
     * @param string $providerType  业务 provider 类型（如 'edgeone' / 'saas'）
     * @param string $label         展示用名称（如 'EdgeOne' / 'SaaS'），用于异常 message
     */
    public function lookupDnspodProviderId(string $providerId, string $providerType, string $label): string
    {
        $provider = $this->providers->requireType(
            $providerId,
            $providerType,
            sprintf('%s provider not found', $label),
            sprintf('%s_provider_not_found', $providerType),
        );

        return trim((string) ($provider['dnspod_provider'] ?? ''));
    }

    /**
     * 同上，未关联抛 ApiException
     */
    public function requireDnspodProviderId(string $providerId, string $providerType, string $label): string
    {
        $id = $this->lookupDnspodProviderId($providerId, $providerType, $label);
        if ($id === '') {
            throw new ApiException(
                sprintf('%s provider is not linked to a DNSPod provider', $label),
                422,
                sprintf('%s_dnspod_provider_missing', $providerType),
                ['provider_id' => $providerId],
            );
        }

        return $id;
    }

    /**
     * 按最长后缀匹配，从 DNSPod 域名列表中找到承接 fqdn 的 zone
     *
     * @param string $errorCodePrefix 抛错时 error code 前缀（如 'edgeone' / 'saas'）
     */
    public function resolveDnspodZone(string $dnspodProviderId, string $fqdn, string $errorCodePrefix): string
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));
        if ($fqdn === '') {
            throw new ApiException(
                'Empty FQDN',
                422,
                sprintf('%s_fqdn_empty', $errorCodePrefix),
            );
        }

        $zones = $this->zones->list($dnspodProviderId, 0, 3000);

        $best = '';
        foreach ($zones['items'] ?? [] as $zone) {
            $name = strtolower((string) ($zone['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (($fqdn === $name || str_ends_with($fqdn, '.' . $name)) && strlen($name) > strlen($best)) {
                $best = $name;
            }
        }

        if ($best === '') {
            throw new ApiException(
                sprintf('No matching DNSPod zone for %s', $fqdn),
                422,
                sprintf('%s_dnspod_zone_not_found', $errorCodePrefix),
                ['fqdn' => $fqdn],
            );
        }

        return $best;
    }

    public function requireExplicitDnspodZone(string $dnspodProviderId, string $zoneName, string $errorCodePrefix): string
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            throw new ApiException(
                'DNSPod zone is required',
                422,
                sprintf('%s_dnspod_zone_not_found', $errorCodePrefix),
            );
        }

        $zones = $this->zones->list($dnspodProviderId, 0, 3000);
        foreach ($zones['items'] ?? [] as $zone) {
            $name = strtolower((string) ($zone['name'] ?? ''));
            if ($name === $zoneName) {
                return $name;
            }
        }

        throw new ApiException(
            sprintf('DNSPod zone %s not found', $zoneName),
            422,
            sprintf('%s_dnspod_zone_not_found', $errorCodePrefix),
            ['zone' => $zoneName],
        );
    }

    /**
     * 同步前清理冲突记录：删除同名的 A/AAAA/MX/NS 等（与 CNAME 在 DNS 规范上冲突）
     *
     * 保留 CNAME（交给上层 DnsPodRecordSync 走 update/unchanged）和 TXT（用户可能另作他用）。
     *
     * @return array<int, array{type:string, name:string, value:string, record_id:string, status:string, error?:string}>
     */
    public function precleanConflicts(string $dnspodProviderId, string $dnspodZone, string $fqdn): array
    {
        $subdomain = $this->recordSync->subdomainFromFqdn($fqdn, $dnspodZone);

        try {
            $listing = $this->records->list($dnspodProviderId, $dnspodZone, [
                'offset' => 0,
                'limit' => 100,
                'subdomain' => $subdomain,
                'record_type' => '',
                'keyword' => '',
                'refresh' => true,
            ]);
        } catch (\Throwable) {
            return [];
        }

        $keepTypes = ['CNAME', 'TXT'];
        $deleted = [];

        foreach ($listing['items'] ?? [] as $item) {
            $type = (string) ($item['type'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($name !== $subdomain || $type === '' || in_array($type, $keepTypes, true)) {
                continue;
            }
            $recordId = (string) ($item['id'] ?? '');
            $entry = [
                'type' => $type,
                'name' => $fqdn,
                'value' => (string) ($item['value'] ?? ''),
                'record_id' => $recordId,
            ];
            if ($recordId === '') {
                $deleted[] = $entry + ['status' => 'not_found'];
                continue;
            }
            try {
                $this->records->delete($dnspodProviderId, $dnspodZone, $recordId);
                $deleted[] = $entry + ['status' => 'deleted'];
            } catch (\Throwable $e) {
                $deleted[] = $entry + ['status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return $deleted;
    }

    /**
     * 删除 subdomain 下 name + type + line 匹配的所有记录（不按 value 精确匹配）
     *
     * 用于业务模块的批量清理场景（如 EdgeOne 删除域名时不知道当时的 CNAME 值，
     * saas active 后清理 ownership TXT）。
     *
     * @return array<int, array{type:string, name:string, record_id:string, status:string, error?:string}>
     */
    public function deleteRecordsByNameType(string $dnspodProviderId, string $dnspodZone, string $fqdn, string $type, string $line = '默认'): array
    {
        $subdomain = $this->recordSync->subdomainFromFqdn($fqdn, $dnspodZone);
        $results = [];

        try {
            $listing = $this->records->list($dnspodProviderId, $dnspodZone, [
                'offset' => 0,
                'limit' => 100,
                'subdomain' => $subdomain,
                'record_type' => $type,
                'keyword' => '',
                'refresh' => true,
            ]);
        } catch (\Throwable) {
            return [];
        }

        foreach ($listing['items'] ?? [] as $item) {
            if (
                (string) ($item['type'] ?? '') !== $type
                || (string) ($item['name'] ?? '') !== $subdomain
                || (string) ($item['line'] ?? '默认') !== $line
            ) {
                continue;
            }
            $recordId = (string) ($item['id'] ?? '');
            $entry = ['type' => $type, 'name' => $fqdn, 'record_id' => $recordId];
            if ($recordId === '') {
                $results[] = $entry + ['status' => 'not_found'];
                continue;
            }
            try {
                $this->records->delete($dnspodProviderId, $dnspodZone, $recordId);
                $results[] = $entry + ['status' => 'deleted'];
            } catch (\Throwable $e) {
                $results[] = $entry + ['status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }
}
