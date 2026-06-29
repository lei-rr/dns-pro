<?php

declare(strict_types=1);

namespace app\service\hostname;

use app\exception\ApiException;
use app\service\dnspod\DnsPodRecordSync;
use app\service\dnspod\DnsPodSyncSupport;

/**
 * Hostname → DNSPod 同步服务
 *
 * 当 hostname provider 关联 DNSPod provider 时，把 Cloudflare for SaaS 自定义主机名的 DNS 记录
 * 自动同步/清理到 DNSPod：
 *   1. 回源 CNAME (默认线路):   <hostname>                  → custom_origin_server / zone fallback origin
 *   2. 所有权 TXT (默认线路):   ownership_verification.name → .value
 *   3. DCV 委派 CNAME (默认):  _acme-challenge.<hostname>  → <hostname>.<uuid>.dcv.cloudflare.com
 *   4. 优选 CNAME (境内线路):   <hostname>                  → custom_metadata.preferred_domain
 *
 * 通用的 zone 匹配 / 冲突清理 / provider 查找逻辑委托给 DnsPodSyncSupport（dnspod 模块），
 * 本类保留 hostname 业务特有的多记录拼装、active 状态判断、ownership 清理等逻辑。
 */
class HostnameSyncService
{
    /** purpose → 短描述(remark 末尾会拼上 hostname fqdn) */
    private const PURPOSE_LABELS = [
        'origin_cname' => '默认回源',
        'ownership_verification' => '所有权验证',
        'dcv_delegation' => 'DCV 委派',
        'preferred_cname' => '优选域名',
    ];

    /** 优选 CNAME 使用的 DNSPod 线路(DNSPod 官方线路名是"境内") */
    private const PREFERRED_LINE = '境内';
    /** 回源 CNAME 默认线路 */
    private const DEFAULT_LINE = '默认';

    private const PROVIDER_TYPE = 'hostname';
    private const PROVIDER_LABEL = 'Hostname';

    public function __construct(
        private readonly DnsPodSyncSupport $support,
        private readonly DnsPodRecordSync $dnspodSync,
        private readonly HostnameService $hostnames,
    ) {
    }

    /**
     * 同步 DNS 记录到 DNSPod
     *
     * 同步前清理 DNSPod 中可能与新 CNAME 冲突的同名旧记录:
     *   - A / AAAA / MX / NS 等都会与 CNAME 冲突,DNS 规范不允许
     *   - 保留 TXT(允许多条同名共存,用户可能另作他用)
     *   - 保留同名同类型(CNAME) — 由 dnspodSync->sync 决定 update / unchanged
     */
    public function sync(string $providerId, string $cfZoneName, string $hostnameId): array
    {
        $dnspodProviderId = $this->support->requireDnspodProviderId($providerId, self::PROVIDER_TYPE, self::PROVIDER_LABEL);
        $hostname = $this->hostnames->showHostname($providerId, $cfZoneName, $hostnameId);
        $fqdn = $this->requireFqdn($hostname);
        $dnspodZone = $this->support->resolveDnspodZone($dnspodProviderId, $fqdn, self::PROVIDER_TYPE);
        $records = $this->collectRecords($hostname, $this->resolveEffectiveOrigin($providerId, $cfZoneName, $hostname));

        if ($records === []) {
            throw new ApiException(
                'No records available for sync',
                422,
                'hostname_no_sync_records',
                ['provider_id' => $providerId, 'hostname_id' => $hostnameId],
            );
        }

        $precleaned = $this->support->precleanConflicts($dnspodProviderId, $dnspodZone, $fqdn);

        $results = array_map(
            fn (array $rec) => $this->withPurpose($rec, $this->dnspodSync->sync($dnspodProviderId, $dnspodZone, $rec)),
            $records,
        );

        return [
            'hostname_id' => $hostnameId,
            'hostname' => $fqdn,
            'dnspod_zone' => $dnspodZone,
            'precleaned' => $precleaned,
            'records' => $results,
        ];
    }

    /**
     * 清理 hostname 对应的 DNS 记录（hostname 已被 CF 删除时调用）
     *
     * @param array $records 删除前从 collectRecordsFor 拿到的记录列表
     */
    public function cleanup(string $providerId, string $hostnameFqdn, array $records): array
    {
        if ($records === [] || $hostnameFqdn === '') {
            return ['cleaned' => 0, 'records' => []];
        }

        $dnspodProviderId = $this->support->lookupDnspodProviderId($providerId, self::PROVIDER_TYPE, self::PROVIDER_LABEL);
        if ($dnspodProviderId === '') {
            return ['cleaned' => 0, 'records' => []];
        }

        try {
            $dnspodZone = $this->support->resolveDnspodZone($dnspodProviderId, $hostnameFqdn, self::PROVIDER_TYPE);
        } catch (ApiException) {
            return ['cleaned' => 0, 'records' => [], 'reason' => 'dnspod_zone_not_found'];
        }

        $results = array_map(
            fn (array $rec) => $this->withPurpose($rec, $this->dnspodSync->delete($dnspodProviderId, $dnspodZone, $rec)),
            $records,
        );

        return [
            'cleaned' => count(array_filter($results, static fn (array $r) => ($r['status'] ?? '') === 'deleted')),
            'dnspod_zone' => $dnspodZone,
            'records' => $results,
        ];
    }

    /**
     * 收集 hostname 对应的全部 DNS 记录(用于"删除前先收集，删除后传给 cleanup")
     *
     * @return array{hostname_fqdn:string, records:array}
     */
    public function collectRecordsFor(string $providerId, string $cfZoneName, string $hostnameId): array
    {
        $hostname = $this->hostnames->showHostname($providerId, $cfZoneName, $hostnameId);

        return [
            'hostname_fqdn' => (string) ($hostname['hostname'] ?? ''),
            'records' => $this->collectRecords($hostname, $this->resolveEffectiveOrigin($providerId, $cfZoneName, $hostname), true),
        ];
    }

    /**
     * 清理 hostname 在 DNSPod 中已经"过期"的辅助记录
     *
     * 触发时机:刷新 hostname 状态后,如果发现状态已变为 active,所有权验证 TXT 没有继续保留的必要。
     *
     * 行为:
     *   - hostname 状态不是 active → 不做任何事(返 ['enabled' => true, 'cleaned' => 0])
     *   - 没关联 DNSPod / DNSPod zone 找不到 → 跳过
     *   - 否则按 (name + type + line + value) 精确删除 DNSPod 中的所有权 TXT
     */
    public function cleanupStaleRecords(string $providerId, string $cfZoneName, string $hostnameFqdn): array
    {
        $hostname = $this->hostnames->showHostname($providerId, $cfZoneName, $hostnameFqdn, true);
        if (!$this->isHostnameActive($hostname)) {
            return ['cleaned' => 0, 'reason' => 'hostname_not_active'];
        }

        $fqdn = (string) ($hostname['hostname'] ?? '');
        if ($fqdn === '') {
            return ['cleaned' => 0, 'reason' => 'fqdn_missing'];
        }

        $dnspodProviderId = $this->support->lookupDnspodProviderId($providerId, self::PROVIDER_TYPE, self::PROVIDER_LABEL);
        if ($dnspodProviderId === '') {
            return ['cleaned' => 0, 'reason' => 'dnspod_provider_missing'];
        }

        try {
            $dnspodZone = $this->support->resolveDnspodZone($dnspodProviderId, $fqdn, self::PROVIDER_TYPE);
        } catch (ApiException) {
            return ['cleaned' => 0, 'reason' => 'dnspod_zone_not_found'];
        }

        // 所有权 TXT 的命名规则固定:_cf-custom-hostname.<hostname-fqdn>
        $ownershipFqdn = '_cf-custom-hostname.' . $fqdn;
        $deleted = $this->support->deleteRecordsByNameType($dnspodProviderId, $dnspodZone, $ownershipFqdn, 'TXT', self::DEFAULT_LINE);

        return [
            'cleaned' => count(array_filter($deleted, static fn (array $r) => ($r['status'] ?? '') === 'deleted')),
            'dnspod_zone' => $dnspodZone,
            'records' => $deleted,
        ];
    }

    /**
     * 创建前预检：autoSync 开启时确保 hostname FQDN 能匹配到 DNSPod zone
     */
    public function preflight(string $providerId, string $hostnameFqdn): array
    {
        $dnspodProviderId = $this->support->requireDnspodProviderId($providerId, self::PROVIDER_TYPE, self::PROVIDER_LABEL);
        $fqdn = trim($hostnameFqdn);
        if ($fqdn === '') {
            throw new ApiException('Hostname FQDN missing', 422, 'hostname_fqdn_missing');
        }

        $dnspodZone = $this->support->resolveDnspodZone($dnspodProviderId, $fqdn, self::PROVIDER_TYPE);

        return [
            'dnspod_provider_id' => $dnspodProviderId,
            'dnspod_zone' => $dnspodZone,
            'hostname_fqdn' => $fqdn,
        ];
    }

    /**
     * hostname 编辑后，把 DNSPod 中旧的关联记录删掉，再同步新的目标记录。
     *
     * @param array<int, array<string, mixed>> $beforeRecords 编辑前 collectRecordsFor() 收集到的旧记录
     */
    public function resyncAfterUpdate(string $providerId, string $cfZoneName, string $hostnameFqdn, array $beforeRecords): array
    {
        $hostname = $this->hostnames->showHostname($providerId, $cfZoneName, $hostnameFqdn, true);
        $fqdn = $this->requireFqdn($hostname);

        $dnspodProviderId = $this->support->lookupDnspodProviderId($providerId, self::PROVIDER_TYPE, self::PROVIDER_LABEL);
        if ($dnspodProviderId === '') {
            return ['cleaned' => 0, 'records' => [], 'deleted' => [], 'reason' => 'dnspod_provider_missing'];
        }

        try {
            $dnspodZone = $this->support->resolveDnspodZone($dnspodProviderId, $fqdn, self::PROVIDER_TYPE);
        } catch (ApiException) {
            return ['cleaned' => 0, 'records' => [], 'deleted' => [], 'reason' => 'dnspod_zone_not_found'];
        }

        $afterRecords = $this->collectRecords($hostname, $this->resolveEffectiveOrigin($providerId, $cfZoneName, $hostname));
        $deleted = $this->deleteMissingRecords($dnspodProviderId, $dnspodZone, $beforeRecords, $afterRecords);
        $precleaned = $afterRecords === []
            ? []
            : $this->support->precleanConflicts($dnspodProviderId, $dnspodZone, $fqdn);
        $results = array_map(
            fn (array $rec) => $this->withPurpose($rec, $this->dnspodSync->sync($dnspodProviderId, $dnspodZone, $rec)),
            $afterRecords,
        );

        return [
            'hostname' => $fqdn,
            'dnspod_zone' => $dnspodZone,
            'cleaned' => count(array_filter($deleted, static fn (array $r) => ($r['status'] ?? '') === 'deleted')),
            'deleted' => $deleted,
            'precleaned' => $precleaned,
            'records' => $results,
        ];
    }

    // ---------- 内部 ----------

    private function requireFqdn(array $hostname): string
    {
        $fqdn = (string) ($hostname['hostname'] ?? '');
        if ($fqdn === '') {
            throw new ApiException('Hostname FQDN missing', 422, 'hostname_fqdn_missing');
        }

        return $fqdn;
    }

    /**
     * 实际回源：优先 custom_origin_server，否则 zone fallback origin
     */
    private function resolveEffectiveOrigin(string $providerId, string $cfZoneName, array $hostname): string
    {
        $custom = trim((string) ($hostname['custom_origin_server'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        try {
            return (string) ($this->hostnames->fallbackOrigin($providerId, $cfZoneName) ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 收集当前应在 DNSPod 维持的 DNS 记录
     *
     * 添加顺序(影响 DNSPod 列表显示顺序):
     *   1. 回源 CNAME(默认线路)
     *   2. 优选 CNAME(境内线路,custom_metadata.preferred_domain 非空时)
     *   3. DCV 委派 CNAME(ssl.dcv_delegation_records[],永久有效)
     *   4. 所有权验证 TXT(_cf-custom-hostname.<host>)
     *
     * hostname 状态进入终态(如 active)后,所有权 TXT 已完成验证使命,默认不再输出。
     * 这样同步会自然驱动后续 cleanup 把那条 TXT 从 DNSPod 删除。
     *
     * @param bool $includeAll true 时强制包含所有权 TXT(不论状态),用于 hostname 删除前的全量收集
     */
    private function collectRecords(array $hostname, string $effectiveOrigin, bool $includeAll = false): array
    {
        $records = [];
        $fqdn = (string) ($hostname['hostname'] ?? '');
        $shouldOutputOwnership = $includeAll || !$this->isHostnameActive($hostname);

        // 1. 回源 CNAME(默认线路)
        if ($fqdn !== '' && $effectiveOrigin !== '') {
            $records[] = $this->record('CNAME', $fqdn, $effectiveOrigin, 'origin_cname', $fqdn, self::DEFAULT_LINE);
        }

        // 2. 优选 CNAME(境内线路)
        $preferred = trim((string) ($hostname['custom_metadata']['preferred_domain'] ?? ''));
        if ($fqdn !== '' && $preferred !== '') {
            $records[] = $this->record('CNAME', $fqdn, $preferred, 'preferred_cname', $fqdn, self::PREFERRED_LINE);
        }

        // 3. DCV 委派 CNAME
        //    Cloudflare 在刚创建 hostname 时 ssl.dcv_delegation_records 可能为空,但 zone 级 dcv_delegation_uuid
        //    立刻可用,且 DCV 委派 CNAME 的命名规则固定:
        //      name:   _acme-challenge.<hostname>
        //      target: <hostname>.<uuid>.dcv.cloudflare.com
        //    所以 records 为空时直接用 uuid 拼,保证创建时立即可同步。
        $dcvAdded = false;
        foreach ((array) ($hostname['ssl']['dcv_delegation_records'] ?? []) as $rec) {
            if (!is_array($rec)) continue;
            $cname = (string) ($rec['cname'] ?? '');
            $target = (string) ($rec['cname_target'] ?? '');
            if ($cname !== '' && $target !== '') {
                $records[] = $this->record('CNAME', $cname, $target, 'dcv_delegation', $fqdn, self::DEFAULT_LINE);
                $dcvAdded = true;
            }
        }
        if (!$dcvAdded && $fqdn !== '') {
            $uuid = trim((string) ($hostname['ssl']['dcv_delegation_uuid'] ?? ''));
            if ($uuid !== '') {
                $records[] = $this->record(
                    'CNAME',
                    '_acme-challenge.' . $fqdn,
                    $fqdn . '.' . $uuid . '.dcv.cloudflare.com',
                    'dcv_delegation',
                    $fqdn,
                    self::DEFAULT_LINE,
                );
            }
        }

        // 4. 所有权验证 TXT —— hostname 已 active 时不再输出(后续会被 cleanup 清掉);
        //    删除前的全量收集(includeAll=true)会强制带上,以便清理 DNSPod 中残留
        if ($shouldOutputOwnership) {
            $ownership = $hostname['ownership_verification'] ?? null;
            if (is_array($ownership) && ($ownership['name'] ?? '') !== '' && ($ownership['value'] ?? '') !== '') {
                $records[] = $this->record('TXT', (string) $ownership['name'], (string) $ownership['value'], 'ownership_verification', $fqdn, self::DEFAULT_LINE);
            }
        }

        return $records;
    }

    /**
     * hostname 是否已经处于"所有权已通过"的状态
     */
    private function isHostnameActive(array $hostname): bool
    {
        $status = (string) ($hostname['status'] ?? '');
        return in_array($status, ['active', 'active_renewing', 'moved'], true);
    }

    private function record(string $type, string $name, string $value, string $purpose, string $fqdn, string $line = self::DEFAULT_LINE): array
    {
        $label = self::PURPOSE_LABELS[$purpose] ?? '自定义主机名';
        return [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'line' => $line,
            'purpose' => $purpose,
            'remark' => trim(sprintf('%s丨%s', $label, $fqdn)),
        ];
    }

    private function withPurpose(array $record, array $result): array
    {
        return ['purpose' => $record['purpose']] + $result;
    }

    /**
     * @param array<int, array<string, mixed>> $beforeRecords
     * @param array<int, array<string, mixed>> $afterRecords
     * @return array<int, array<string, mixed>>
     */
    private function deleteMissingRecords(string $dnspodProviderId, string $dnspodZone, array $beforeRecords, array $afterRecords): array
    {
        $afterMap = [];
        foreach ($afterRecords as $record) {
            if (!is_array($record)) {
                continue;
            }
            $afterMap[$this->recordSignature($record)] = true;
        }

        $deleted = [];
        $seen = [];
        foreach ($beforeRecords as $record) {
            if (!is_array($record)) {
                continue;
            }

            $signature = $this->recordSignature($record);
            if ($signature === '' || isset($seen[$signature]) || isset($afterMap[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $deleted[] = $this->withPurpose($record, $this->dnspodSync->delete($dnspodProviderId, $dnspodZone, $record));
        }

        return $deleted;
    }

    private function recordSignature(array $record): string
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        $name = strtolower(rtrim(trim((string) ($record['name'] ?? '')), '.'));
        $value = strtolower(rtrim(trim((string) ($record['value'] ?? '')), '.'));
        $line = trim((string) ($record['line'] ?? self::DEFAULT_LINE));

        if ($type === '' || $name === '' || $value === '') {
            return '';
        }

        return implode('|', [$type, $name, $value, $line]);
    }
}
