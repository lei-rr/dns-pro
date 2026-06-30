<?php

declare(strict_types=1);

namespace app\service\dnspod;

/**
 * DNSPod 单条记录增量同步原语
 *
 * 提供"按 name + type 维度幂等同步/校验/删除一条 DNS 记录"的能力，业务无关。
 * 调用方传入完整 record（type/name/value/remark），本类负责：
 *   - 查找同 subdomain + type 的现有记录
 *   - 与目标值对比，决定 create / update / unchanged / delete
 *   - 失败时返回 status=failed + error，调用方按需聚合
 *
 * 上层调用方（如 SaasSyncService）只需关心业务语义（origin_cname / ownership / dcv），
 * 不需要重复实现"查→比→改"的细节。
 */
class DnsPodRecordSync
{
    public function __construct(
        private readonly DnsPodRecordGateway $records,
    ) {
    }

    /**
     * 把一条 DNS 记录同步到 DNSPod（幂等）
     *
     * @param array{type:string,name:string,value:string,line?:string,remark?:string,ttl?:int} $record
     *        line 默认 "默认"，可指定为 "境内" / "境外" / "电信" 等 DNSPod 线路
     * @return array{type:string,name:string,value:string,status:string,record_id:string,error?:string}
     */
    public function sync(string $dnspodProviderId, string $dnspodZone, array $record): array
    {
        $base = $this->baseResult($record);

        try {
            $subdomain = $this->subdomainFromFqdn($record['name'], $dnspodZone);
            $line = $record['line'] ?? '默认';
            $matches = $this->findMatching($dnspodProviderId, $dnspodZone, $subdomain, $record['type'], $line);
            $payload = $this->buildPayload($record, $subdomain, $line);

            if ($matches === []) {
                $created = $this->records->create($dnspodProviderId, $dnspodZone, $payload);
                return $base + ['status' => 'created', 'record_id' => (string) ($created['id'] ?? '')];
            }

            // 找到同值的记录:value 一致就只看 remark/ttl 是否需要更新
            $expectedRemark = (string) ($record['remark'] ?? '');
            foreach ($matches as $match) {
                if ((string) ($match['value'] ?? '') !== $record['value']) {
                    continue;
                }
                $currentRemark = (string) ($match['remark'] ?? '');
                $currentTtl = (int) ($match['ttl'] ?? 600);
                $expectedTtl = (int) ($payload['ttl'] ?? 600);
                if ($currentRemark === $expectedRemark && $currentTtl === $expectedTtl) {
                    return $base + ['status' => 'unchanged', 'record_id' => (string) ($match['id'] ?? '')];
                }
                // value 相同但 remark/ttl 不同 → 更新记录
                $recordId = (string) ($match['id'] ?? '');
                if ($recordId !== '') {
                    $this->records->update($dnspodProviderId, $dnspodZone, $recordId, $payload);
                    return $base + ['status' => 'updated', 'record_id' => $recordId];
                }
            }

            // 同名同类型同线路但值不同 → update 第一条
            $first = $matches[0];
            $recordId = (string) ($first['id'] ?? '');
            if ($recordId !== '') {
                $this->records->update($dnspodProviderId, $dnspodZone, $recordId, $payload);
                return $base + ['status' => 'updated', 'record_id' => $recordId];
            }

            $created = $this->records->create($dnspodProviderId, $dnspodZone, $payload);
            return $base + ['status' => 'created', 'record_id' => (string) ($created['id'] ?? '')];
        } catch (\Throwable $e) {
            return $base + ['status' => 'failed', 'record_id' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * 检查一条 DNS 记录是否已经在 DNSPod 中存在且值正确
     */
    public function check(string $dnspodProviderId, string $dnspodZone, array $record): array
    {
        $subdomain = $this->subdomainFromFqdn($record['name'], $dnspodZone);
        $matches = $this->findMatching($dnspodProviderId, $dnspodZone, $subdomain, $record['type'], $record['line'] ?? '默认');

        $expected = rtrim($record['value'], '.');
        $synced = false;
        foreach ($matches as $match) {
            if (rtrim((string) ($match['value'] ?? ''), '.') === $expected) {
                $synced = true;
                break;
            }
        }

        return $this->baseResult($record) + ['synced' => $synced];
    }

    /**
     * 从 DNSPod 删除匹配 name+type+line+value 的记录
     */
    public function delete(string $dnspodProviderId, string $dnspodZone, array $record): array
    {
        $subdomain = $this->subdomainFromFqdn($record['name'], $dnspodZone);
        $matches = $this->findMatching($dnspodProviderId, $dnspodZone, $subdomain, $record['type'], $record['line'] ?? '默认');

        $expected = rtrim($record['value'], '.');
        $match = null;
        foreach ($matches as $candidate) {
            if (rtrim((string) ($candidate['value'] ?? ''), '.') === $expected) {
                $match = $candidate;
                break;
            }
        }

        $base = [
            'type' => $record['type'],
            'name' => $record['name'],
        ];

        if ($match === null) {
            return $base + ['status' => 'not_found', 'record_id' => ''];
        }

        $recordId = (string) ($match['id'] ?? '');
        try {
            $this->records->delete($dnspodProviderId, $dnspodZone, $recordId);
            return $base + ['status' => 'deleted', 'record_id' => $recordId];
        } catch (\Throwable $e) {
            return $base + ['status' => 'failed', 'record_id' => $recordId, 'error' => $e->getMessage()];
        }
    }

    /**
     * 拼 FQDN → subdomain（DNSPod API 的 SubDomain 字段）
     * 例如 fqdn=cc.guolei.cc, zone=guolei.cc → cc
     */
    public function subdomainFromFqdn(string $fqdn, string $zoneName): string
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));
        $zone = strtolower($zoneName);

        if ($fqdn === $zone) {
            return '@';
        }

        $suffix = '.' . $zone;
        if (str_ends_with($fqdn, $suffix)) {
            return substr($fqdn, 0, -strlen($suffix));
        }

        return $fqdn;
    }

    private function findMatching(string $dnspodProviderId, string $dnspodZone, string $subdomain, string $type, string $line = '默认'): array
    {
        $existing = $this->records->list($dnspodProviderId, $dnspodZone, [
            'offset' => 0,
            'limit' => 100,
            'subdomain' => $subdomain,
            'record_type' => $type,
            'keyword' => '',
        ]);

        return array_values(array_filter(
            $existing['items'] ?? [],
            static fn (array $r) => ($r['type'] ?? '') === $type
                && (string) ($r['name'] ?? '') === $subdomain
                && (string) ($r['line'] ?? '默认') === $line,
        ));
    }

    private function buildPayload(array $record, string $subdomain, string $line = '默认'): array
    {
        return [
            'record_type' => $record['type'],
            'record_line' => $line,
            'value' => $record['value'],
            'subdomain' => $subdomain,
            'ttl' => $record['ttl'] ?? 600,
            'remark' => $record['remark'] ?? '',
        ];
    }

    private function baseResult(array $record): array
    {
        return [
            'type' => $record['type'],
            'name' => $record['name'],
            'value' => $record['value'],
        ];
    }
}
