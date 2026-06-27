<?php

declare(strict_types=1);

namespace app\service\edgeone;

use app\exception\ApiException;
use app\service\dnspod\DnsPodRecordSync;
use app\service\dnspod\DnsPodSyncSupport;

/**
 * EdgeOne → DNSPod 同步服务
 *
 * 当 EdgeOne provider 关联 DNSPod provider 时，把加速域名的 CNAME 记录
 * 自动同步/清理到 DNSPod：
 *   - 创建加速域名后：同步 CNAME 到 DNSPod（默认线路）
 *   - 删除加速域名时：清理 DNSPod 中对应的 CNAME
 *
 * 通用的 zone 匹配 / 冲突清理 / provider 查找逻辑委托给 DnsPodSyncSupport（dnspod 模块），
 * 本类只保留 EdgeOne 业务特有的记录构造和流程编排。
 */
class EdgeOneSyncService
{
    private const PROVIDER_TYPE = 'edgeone';
    private const PROVIDER_LABEL = 'EdgeOne';
    private const DEFAULT_LINE = '默认';

    public function __construct(
        private readonly DnsPodSyncSupport $support,
        private readonly DnsPodRecordSync $dnspodSync,
    ) {
    }

    /**
     * 创建前预检：确保加速域名 FQDN 能匹配到 DNSPod zone
     */
    public function preflight(string $providerId, string $domainName): array
    {
        $dnspodProviderId = $this->support->requireDnspodProviderId($providerId, self::PROVIDER_TYPE, self::PROVIDER_LABEL);
        $fqdn = strtolower(trim($domainName));
        if ($fqdn === '') {
            throw new ApiException('Domain name missing', 422, 'edgeone_domain_name_missing');
        }

        $dnspodZone = $this->support->resolveDnspodZone($dnspodProviderId, $fqdn, self::PROVIDER_TYPE);

        return [
            'dnspod_provider_id' => $dnspodProviderId,
            'dnspod_zone' => $dnspodZone,
            'domain_name' => $fqdn,
        ];
    }

    /**
     * 同步加速域名 CNAME 到 DNSPod
     *
     * @param string $cname EdgeOne 分配的 CNAME 值（如 xxx.eo.dnse1.com）
     */
    public function sync(string $providerId, string $domainName, string $cname): array
    {
        $dnspodProviderId = $this->support->requireDnspodProviderId($providerId, self::PROVIDER_TYPE, self::PROVIDER_LABEL);
        $fqdn = strtolower(trim($domainName));
        $dnspodZone = $this->support->resolveDnspodZone($dnspodProviderId, $fqdn, self::PROVIDER_TYPE);

        if ($cname === '') {
            throw new ApiException(
                'EdgeOne CNAME not available yet',
                422,
                'edgeone_cname_not_available',
                ['domain_name' => $fqdn],
            );
        }

        $record = $this->buildRecord($fqdn, $cname);
        $precleaned = $this->support->precleanConflicts($dnspodProviderId, $dnspodZone, $fqdn);
        $result = $this->dnspodSync->sync($dnspodProviderId, $dnspodZone, $record);

        return [
            'domain_name' => $fqdn,
            'dnspod_zone' => $dnspodZone,
            'precleaned' => $precleaned,
            'record' => $result,
        ];
    }

    /**
     * 清理加速域名在 DNSPod 中的 CNAME 记录（域名删除时调用）
     *
     * @param string $cname 待清理的 CNAME 值；为空则按 name+type+line 模糊删除
     */
    public function cleanup(string $providerId, string $domainName, string $cname = ''): array
    {
        if ($domainName === '') {
            return ['cleaned' => 0, 'records' => []];
        }

        $dnspodProviderId = $this->support->lookupDnspodProviderId($providerId, self::PROVIDER_TYPE, self::PROVIDER_LABEL);
        if ($dnspodProviderId === '') {
            return ['cleaned' => 0, 'records' => []];
        }

        $fqdn = strtolower(trim($domainName));

        try {
            $dnspodZone = $this->support->resolveDnspodZone($dnspodProviderId, $fqdn, self::PROVIDER_TYPE);
        } catch (ApiException) {
            return ['cleaned' => 0, 'records' => [], 'reason' => 'dnspod_zone_not_found'];
        }

        if ($cname !== '') {
            $record = $this->buildRecord($fqdn, $cname);
            $result = $this->dnspodSync->delete($dnspodProviderId, $dnspodZone, $record);

            return [
                'cleaned' => ($result['status'] ?? '') === 'deleted' ? 1 : 0,
                'dnspod_zone' => $dnspodZone,
                'records' => [$result],
            ];
        }

        // 模糊删除：该 subdomain 下默认线路的所有 CNAME
        $results = $this->support->deleteRecordsByNameType($dnspodProviderId, $dnspodZone, $fqdn, 'CNAME', self::DEFAULT_LINE);

        return [
            'cleaned' => count(array_filter($results, static fn (array $r) => ($r['status'] ?? '') === 'deleted')),
            'dnspod_zone' => $dnspodZone,
            'records' => $results,
        ];
    }

    // ---------- 业务专属 ----------

    private function buildRecord(string $fqdn, string $cname): array
    {
        return [
            'type' => 'CNAME',
            'name' => $fqdn,
            'value' => $cname,
            'line' => self::DEFAULT_LINE,
            'remark' => sprintf('EdgeOne 加速丨%s', $fqdn),
        ];
    }
}
