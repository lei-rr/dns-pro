<?php

declare(strict_types=1);

namespace app\service\edgeone;

use app\exception\ApiException;
use TencentCloud\Teo\V20220901\Models\AccelerationDomain;
use TencentCloud\Teo\V20220901\Models\Zone;

class EdgeOneMapper
{
    public function normalizeZoneFilters(array $filters): array
    {
        return [
            'offset' => (int) ($filters['offset'] ?? 0),
            'limit' => (int) ($filters['limit'] ?? 20),
            'refresh' => (bool) ($filters['refresh'] ?? false),
        ];
    }

    public function normalizeDomainFilters(array $filters): array
    {
        return [
            'offset' => (int) ($filters['offset'] ?? 0),
            'limit' => (int) ($filters['limit'] ?? 20),
            'refresh' => (bool) ($filters['refresh'] ?? false),
        ];
    }

    public function normalizeAccelerationDomainData(array $data): array
    {
        if (isset($data['domain_name'])) {
            $data['domain_name'] = strtolower(trim((string) $data['domain_name']));
        }

        $data['origin'] = trim((string) ($data['origin'] ?? ''));
        $data['origin_type'] = strtoupper(trim((string) ($data['origin_type'] ?? 'IP_DOMAIN')));
        $data['host_header'] = strtolower(trim((string) ($data['host_header'] ?? '')));
        $data['origin_protocol'] = strtoupper(trim((string) ($data['origin_protocol'] ?? 'FOLLOW')));
        $data['http_origin_port'] = (int) ($data['http_origin_port'] ?? 80);
        $data['https_origin_port'] = (int) ($data['https_origin_port'] ?? 443);
        $data['ipv6_status'] = strtolower(trim((string) ($data['ipv6_status'] ?? 'follow')));

        return $data;
    }

    public function normalizeCertificateData(array $data): array
    {
        $data['https_mode'] = (string) ($data['https_mode'] ?? '');
        $data['cert_id'] = trim((string) ($data['cert_id'] ?? ''));

        if ($data['https_mode'] === 'sslcert' && $data['cert_id'] === '') {
            throw new ApiException('Certificate id is required when https_mode is sslcert', 422, 'validation_failed', [
                'errors' => ['cert_id' => 'Certificate id is required'],
            ]);
        }

        return $data;
    }

    public function relativeRecordName(string $domainName, string $zoneName): string
    {
        if ($domainName === $zoneName) {
            return '@';
        }

        $suffix = '.' . $zoneName;

        if (!str_ends_with($domainName, $suffix)) {
            throw new ApiException('Acceleration domain does not belong to DNSPod zone', 422, 'edgeone_domain_zone_mismatch', [
                'domain_name' => $domainName,
                'zone_name' => $zoneName,
            ]);
        }

        return substr($domainName, 0, -strlen($suffix));
    }

    public function presentZone(Zone $zone): array
    {
        return [
            'id' => $zone->ZoneId,
            'name' => $zone->ZoneName,
            'area' => $zone->Area,
            'type' => $zone->Type,
            'status' => $zone->Status,
            'active_status' => $zone->ActiveStatus,
            'lock_status' => $zone->LockStatus,
            'paused' => $zone->Paused,
            'created_on' => $zone->CreatedOn,
            'modified_on' => $zone->ModifiedOn,
        ];
    }

    public function presentAccelerationDomain(AccelerationDomain $domain, string $zoneId): array
    {
        return [
            'zone_id' => $domain->ZoneId ?? $zoneId,
            'name' => $domain->DomainName,
            'status' => $domain->DomainStatus,
            'cname' => $domain->Cname,
            'ipv6_status' => $domain->IPv6Status,
            'identification_status' => $domain->IdentificationStatus,
            'origin_protocol' => $domain->OriginProtocol,
            'http_origin_port' => $domain->HttpOriginPort,
            'https_origin_port' => $domain->HttpsOriginPort,
            'origin' => $this->presentOrigin($domain->OriginDetail ?? null),
            'certificate' => $this->presentCertificate($domain->Certificate ?? null),
            'created_on' => $domain->CreatedOn,
            'modified_on' => $domain->ModifiedOn,
        ];
    }

    public function syncResult(bool $synced, string $action, string $message, string $recordId): array
    {
        return [
            'synced' => $synced,
            'action' => $action,
            'message' => $message,
            'record_id' => $recordId,
        ];
    }

    private function presentOrigin(?object $origin): ?array
    {
        if (!$origin) {
            return null;
        }

        return [
            'type' => $origin->OriginType ?? null,
            'value' => $origin->Origin ?? null,
            'host_header' => $origin->HostHeader ?? null,
        ];
    }

    private function presentCertificate(?object $certificate): array
    {
        if (!$certificate) {
            return ['mode' => 'disable', 'items' => []];
        }

        return [
            'mode' => $certificate->Mode ?? 'disable',
            'items' => array_map(static fn (object $item) => [
                'cert_id' => $item->CertId ?? null,
                'alias' => $item->Alias ?? null,
                'type' => $item->Type ?? null,
                'status' => $item->Status ?? null,
                'expire_time' => $item->ExpireTime ?? null,
            ], $certificate->List ?? []),
        ];
    }
}
