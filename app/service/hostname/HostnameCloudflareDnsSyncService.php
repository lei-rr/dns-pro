<?php

declare(strict_types=1);

namespace app\service\hostname;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\cloudflare\CloudflareDnsRecordGateway;
use app\service\cloudflare\CloudflareZoneGateway;

class HostnameCloudflareDnsSyncService
{
    private const PURPOSE_LABELS = [
        'origin_cname' => '业务接入',
        'ownership_verification' => '所有权验证',
        'dcv_delegation' => 'DCV 委派',
    ];

    private const PROVIDER_TYPE = 'hostname';
    private const PROVIDER_LABEL = 'Hostname';

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CloudflareZoneGateway $zones,
        private readonly CloudflareDnsRecordGateway $records,
        private readonly HostnameService $hostnames,
    ) {
    }

    public function preflight(string $providerId, string $hostnameFqdn, array $data = []): array
    {
        [$cloudflareProviderId, $zoneId, $zoneName] = $this->resolveTarget($providerId, $hostnameFqdn, (string) ($data['sync_zone'] ?? ''));

        return [
            'cloudflare_provider_id' => $cloudflareProviderId,
            'cloudflare_zone_id' => $zoneId,
            'cloudflare_zone' => $zoneName,
            'hostname_fqdn' => trim($hostnameFqdn),
        ];
    }

    public function sync(string $providerId, string $cfZoneName, string $hostnameFqdn): array
    {
        [$cloudflareProviderId, $zoneId, $zoneName] = $this->resolveTarget($providerId, $hostnameFqdn);
        $hostname = $this->hostnames->showHostname($providerId, $cfZoneName, $hostnameFqdn);
        $fqdn = $this->requireFqdn($hostname);
        $effectiveOrigin = $this->resolveEffectiveOrigin($providerId, $cfZoneName, $hostname);
        $this->requireBusinessTarget($hostname, $effectiveOrigin);
        $records = $this->collectRecords($hostname, $effectiveOrigin, $zoneName);

        if ($records === []) {
            throw new ApiException(
                'No records available for sync',
                422,
                'hostname_no_sync_records',
                ['provider_id' => $providerId, 'hostname_fqdn' => $hostnameFqdn],
            );
        }

        $results = array_map(
            fn (array $record) => $this->withPurpose($record, $this->syncRecord($cloudflareProviderId, $zoneId, $record)),
            $records,
        );

        return [
            'hostname_fqdn' => $fqdn,
            'hostname' => $fqdn,
            'cloudflare_provider_id' => $cloudflareProviderId,
            'cloudflare_zone' => $zoneName,
            'records' => $results,
        ];
    }

    public function cleanup(string $providerId, string $hostnameFqdn, array $records): array
    {
        if ($records === [] || $hostnameFqdn === '') {
            return ['cleaned' => 0, 'records' => []];
        }

        $cloudflareProviderId = trim((string) ($records[0]['provider_id'] ?? ''));
        if ($cloudflareProviderId === '') {
            $cloudflareProviderId = $this->requireCloudflareDnsProviderId($providerId);
        }
        $zoneName = trim((string) ($records[0]['zone_name'] ?? ''));
        if ($zoneName === '') {
            return ['cleaned' => 0, 'records' => [], 'reason' => 'cloudflare_zone_not_found'];
        }

        try {
            $zoneId = $this->zones->idByName($cloudflareProviderId, $zoneName);
        } catch (ApiException) {
            return ['cleaned' => 0, 'records' => [], 'reason' => 'cloudflare_zone_not_found'];
        }

        $results = array_map(
            fn (array $record) => $this->withPurpose($record, $this->deleteRecord($cloudflareProviderId, $zoneId, $record)),
            $records,
        );

        return [
            'cleaned' => count(array_filter($results, static fn (array $r) => ($r['status'] ?? '') === 'deleted')),
            'cloudflare_zone' => $zoneName,
            'records' => $results,
        ];
    }

    public function collectRecordsFor(string $providerId, string $cfZoneName, string $hostnameFqdn): array
    {
        $hostname = $this->hostnames->showHostname($providerId, $cfZoneName, $hostnameFqdn);
        $sync = $this->hostnames->syncConfig($providerId, (string) ($hostname['hostname'] ?? ''), $cfZoneName);
        $cloudflareProviderId = trim((string) ($sync['sync_provider_id'] ?? ''));
        if ($cloudflareProviderId === '') {
            $cloudflareProviderId = $this->requireCloudflareDnsProviderId($providerId);
        }
        $zoneName = trim((string) ($sync['sync_zone'] ?? ''));

        return [
            'hostname_fqdn' => (string) ($hostname['hostname'] ?? ''),
            'records' => $this->collectRecords($hostname, $this->resolveEffectiveOrigin($providerId, $cfZoneName, $hostname), $zoneName, $cloudflareProviderId, true),
        ];
    }

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

        [$cloudflareProviderId, $zoneId, $zoneName] = $this->resolveTarget($providerId, $fqdn);
        $record = $this->record('TXT', '_cf-custom-hostname.' . $fqdn, (string) ($hostname['ownership_verification']['value'] ?? ''), 'ownership_verification', $fqdn, $zoneName);
        $result = $this->deleteRecord($cloudflareProviderId, $zoneId, $record);

        return [
            'cleaned' => ($result['status'] ?? '') === 'deleted' ? 1 : 0,
            'cloudflare_zone' => $zoneName,
            'records' => [$result],
        ];
    }

    public function resyncAfterUpdate(string $providerId, string $cfZoneName, string $hostnameFqdn, array $beforeRecords): array
    {
        $hostname = $this->hostnames->showHostname($providerId, $cfZoneName, $hostnameFqdn, true);
        $fqdn = $this->requireFqdn($hostname);
        [$cloudflareProviderId, $zoneId, $zoneName] = $this->resolveTarget($providerId, $fqdn);
        $effectiveOrigin = $this->resolveEffectiveOrigin($providerId, $cfZoneName, $hostname);
        $this->requireBusinessTarget($hostname, $effectiveOrigin);
        $afterRecords = $this->collectRecords($hostname, $effectiveOrigin, $zoneName);
        $deleted = $this->deleteMissingRecords($cloudflareProviderId, $zoneId, $beforeRecords, $afterRecords);
        $results = array_map(
            fn (array $record) => $this->withPurpose($record, $this->syncRecord($cloudflareProviderId, $zoneId, $record)),
            $afterRecords,
        );

        return [
            'hostname_fqdn' => $fqdn,
            'cloudflare_zone' => $zoneName,
            'cleaned' => count(array_filter($deleted, static fn (array $r) => ($r['status'] ?? '') === 'deleted')),
            'deleted' => $deleted,
            'records' => $results,
        ];
    }

    private function resolveTarget(string $providerId, string $hostnameFqdn, string $explicitSyncZone = '', string $explicitSyncProviderId = ''): array
    {
        $cloudflareProviderId = trim($explicitSyncProviderId);
        if ($cloudflareProviderId === '') {
            $sync = $this->hostnames->syncConfig($providerId, $hostnameFqdn);
            $cloudflareProviderId = trim((string) ($sync['sync_provider_id'] ?? ''));
        }
        if ($cloudflareProviderId === '') {
            $cloudflareProviderId = $this->requireCloudflareDnsProviderId($providerId);
        }
        $zoneName = strtolower(trim($explicitSyncZone));
        if ($zoneName === '') {
            $sync = $this->hostnames->syncConfig($providerId, $hostnameFqdn);
            $zoneName = strtolower(trim((string) ($sync['sync_zone'] ?? '')));
        }
        if ($zoneName === '') {
            throw new ApiException('Cloudflare DNS sync zone is required', 422, 'hostname_cloudflare_sync_zone_missing', [
                'provider_id' => $providerId,
                'hostname_fqdn' => $hostnameFqdn,
            ]);
        }

        return [$cloudflareProviderId, $this->zones->idByName($cloudflareProviderId, $zoneName), $zoneName];
    }

    private function requireCloudflareDnsProviderId(string $providerId): string
    {
        $provider = $this->providers->requireType(
            $providerId,
            self::PROVIDER_TYPE,
            'Hostname provider not found',
            'hostname_provider_not_found',
        );
        $id = trim((string) ($provider['cloudflare_dns_provider'] ?? ''));
        if ($id === '') {
            $id = trim((string) ($provider['cloudflare_provider'] ?? ''));
        }
        if ($id === '') {
            throw new ApiException('Hostname provider is not linked to a Cloudflare DNS provider', 422, 'hostname_cloudflare_dns_provider_missing', [
                'provider_id' => $providerId,
            ]);
        }

        return $id;
    }

    private function requireFqdn(array $hostname): string
    {
        $fqdn = trim((string) ($hostname['hostname'] ?? ''));
        if ($fqdn === '') {
            throw new ApiException('Hostname FQDN missing', 422, 'hostname_fqdn_missing');
        }

        return $fqdn;
    }

    private function resolveEffectiveOrigin(string $providerId, string $cfZoneName, array $hostname): string
    {
        $custom = trim((string) ($hostname['custom_origin_server'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return (string) ($this->hostnames->fallbackOrigin($providerId, $cfZoneName) ?? '');
    }

    private function requireBusinessTarget(array $hostname, string $effectiveOrigin): void
    {
        $autoPreferred = (bool) ($hostname['auto_preferred'] ?? false);
        $preferred = trim((string) ($hostname['custom_metadata']['preferred_domain'] ?? ''));
        $businessTarget = $autoPreferred && $preferred !== '' ? $preferred : $effectiveOrigin;

        if ($businessTarget === '') {
            throw new ApiException(
                'No business CNAME target available',
                422,
                'hostname_business_target_missing',
            );
        }
    }

    private function collectRecords(array $hostname, string $effectiveOrigin, string $zoneName, string $cloudflareProviderId, bool $includeAll = false): array
    {
        $records = [];
        $fqdn = (string) ($hostname['hostname'] ?? '');
        $shouldOutputOwnership = $includeAll || !$this->isHostnameActive($hostname);
        $autoPreferred = (bool) ($hostname['auto_preferred'] ?? false);
        $preferred = trim((string) ($hostname['custom_metadata']['preferred_domain'] ?? ''));
        $businessTarget = $autoPreferred && $preferred !== '' ? $preferred : $effectiveOrigin;

        if ($fqdn !== '' && $businessTarget !== '') {
            $records[] = $this->record('CNAME', $fqdn, $businessTarget, 'origin_cname', $fqdn, $zoneName, $cloudflareProviderId);
        }

        $dcvAdded = false;
        foreach ((array) ($hostname['ssl']['dcv_delegation_records'] ?? []) as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $cname = (string) ($rec['cname'] ?? '');
            $target = (string) ($rec['cname_target'] ?? '');
            if ($cname !== '' && $target !== '') {
                $records[] = $this->record('CNAME', $cname, $target, 'dcv_delegation', $fqdn, $zoneName, $cloudflareProviderId);
                $dcvAdded = true;
            }
        }

        if (!$dcvAdded && $fqdn !== '') {
            $uuid = trim((string) ($hostname['ssl']['dcv_delegation_uuid'] ?? ''));
            if ($uuid !== '') {
                $records[] = $this->record('CNAME', '_acme-challenge.' . $fqdn, $fqdn . '.' . $uuid . '.dcv.cloudflare.com', 'dcv_delegation', $fqdn, $zoneName, $cloudflareProviderId);
            }
        }

        if ($shouldOutputOwnership) {
            $ownership = $hostname['ownership_verification'] ?? null;
            if (is_array($ownership) && ($ownership['name'] ?? '') !== '' && ($ownership['value'] ?? '') !== '') {
                $records[] = $this->record('TXT', (string) $ownership['name'], (string) $ownership['value'], 'ownership_verification', $fqdn, $zoneName, $cloudflareProviderId);
            }
        }

        return $records;
    }

    private function isHostnameActive(array $hostname): bool
    {
        $status = (string) ($hostname['status'] ?? '');
        return in_array($status, ['active', 'active_renewing', 'moved'], true);
    }

    private function record(string $type, string $name, string $value, string $purpose, string $fqdn, string $zoneName, string $cloudflareProviderId): array
    {
        $label = self::PURPOSE_LABELS[$purpose] ?? '自定义主机名';

        return [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'purpose' => $purpose,
            'provider_id' => $cloudflareProviderId,
            'zone_name' => $zoneName,
            'comment' => trim(sprintf('%s丨%s', $label, $fqdn)),
        ];
    }

    private function withPurpose(array $record, array $result): array
    {
        return ['purpose' => $record['purpose']] + $result;
    }

    private function syncRecord(string $providerId, string $zoneId, array $record): array
    {
        $base = [
            'type' => $record['type'],
            'name' => $record['name'],
            'value' => $record['value'],
        ];

        $matches = $this->exactMatches($providerId, $zoneId, (string) $record['name'], (string) $record['type']);
        $expectedComment = (string) ($record['comment'] ?? '');
        $expectedValue = rtrim((string) $record['value'], '.');

        foreach ($matches as $match) {
            $value = rtrim((string) ($match['content'] ?? ''), '.');
            if ($value === $expectedValue) {
                $comment = (string) ($match['comment'] ?? '');
                if ($comment === $expectedComment || $comment === '') {
                    return $base + ['status' => 'unchanged', 'record_id' => (string) ($match['id'] ?? '')];
                }
            }
        }

        foreach ($matches as $match) {
            if ((string) ($match['comment'] ?? '') !== $expectedComment) {
                continue;
            }

            $updated = $this->records->update($providerId, $zoneId, (string) $match['id'], $this->recordPayload($record));
            return $base + ['status' => 'updated', 'record_id' => (string) ($updated['id'] ?? $match['id'] ?? '')];
        }

        if ($matches !== []) {
            throw new ApiException('Cloudflare DNS record conflict', 409, 'cloudflare_dns_record_conflict', [
                'name' => $record['name'],
                'type' => $record['type'],
            ]);
        }

        $created = $this->records->create($providerId, $zoneId, $this->recordPayload($record));
        return $base + ['status' => 'created', 'record_id' => (string) ($created['id'] ?? '')];
    }

    private function deleteRecord(string $providerId, string $zoneId, array $record): array
    {
        $base = [
            'type' => $record['type'],
            'name' => $record['name'],
        ];
        $expectedValue = rtrim((string) ($record['value'] ?? ''), '.');
        $expectedComment = (string) ($record['comment'] ?? '');

        foreach ($this->exactMatches($providerId, $zoneId, (string) $record['name'], (string) $record['type']) as $match) {
            if (rtrim((string) ($match['content'] ?? ''), '.') !== $expectedValue) {
                continue;
            }

            $comment = (string) ($match['comment'] ?? '');
            if ($comment !== '' && $comment !== $expectedComment) {
                continue;
            }

            $recordId = (string) ($match['id'] ?? '');
            if ($recordId === '') {
                continue;
            }

            $this->records->delete($providerId, $zoneId, $recordId);
            return $base + ['status' => 'deleted', 'record_id' => $recordId];
        }

        return $base + ['status' => 'not_found', 'record_id' => ''];
    }

    private function exactMatches(string $providerId, string $zoneId, string $fqdn, string $type): array
    {
        $page = 1;
        $matches = [];

        do {
            $result = $this->records->list($providerId, $zoneId, [
                'type' => $type,
                'search' => $fqdn,
                'page' => $page,
                'per_page' => 100,
            ]);

            foreach ($result['items'] ?? [] as $record) {
                if (($record['name'] ?? '') === $fqdn && ($record['type'] ?? '') === $type) {
                    $matches[] = $record;
                }
            }

            $page++;
            $totalPages = (int) ($result['pagination']['total_pages'] ?? $result['meta']['total_pages'] ?? 1);
        } while ($page <= $totalPages);

        return $matches;
    }

    private function deleteMissingRecords(string $providerId, string $zoneId, array $beforeRecords, array $afterRecords): array
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
            $deleted[] = $this->withPurpose($record, $this->deleteRecord($providerId, $zoneId, $record));
        }

        return $deleted;
    }

    private function recordSignature(array $record): string
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        $name = strtolower(rtrim(trim((string) ($record['name'] ?? '')), '.'));
        $value = strtolower(rtrim(trim((string) ($record['value'] ?? '')), '.'));

        if ($type === '' || $name === '' || $value === '') {
            return '';
        }

        return implode('|', [$type, $name, $value]);
    }

    private function recordPayload(array $record): array
    {
        return [
            'type' => (string) $record['type'],
            'name' => (string) $record['name'],
            'content' => (string) $record['value'],
            'ttl' => 1,
            'comment' => (string) ($record['comment'] ?? ''),
            'proxied' => false,
        ];
    }
}
