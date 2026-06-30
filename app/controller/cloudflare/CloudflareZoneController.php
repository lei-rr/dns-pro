<?php

declare(strict_types=1);

namespace app\controller\cloudflare;

use app\controller\concerns\ValidatesInput;
use app\service\cloudflare\CloudflareZoneGateway;
use app\support\ApiResponse;
use app\validate\CloudflareZoneValidate;
use think\Response;

class CloudflareZoneController
{
    use ValidatesInput;

    public function __construct(
        private readonly CloudflareZoneGateway $zones,
    ) {
    }

    public function index(string $providerId): Response
    {
        $query = $this->queryInput(CloudflareZoneValidate::class, 'index');

        return ApiResponse::data($this->zones->list(
            $providerId,
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 20),
            trim((string) ($query['name'] ?? '')),
            filter_var($query['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ));
    }

    public function store(string $providerId): Response
    {
        $data = $this->postInput(CloudflareZoneValidate::class, 'store');

        $result = $this->zones->create(
            $providerId,
            strtolower(trim((string) $data['name'])),
            (string) ($data['type'] ?? 'full'),
        );

        return ApiResponse::data($result, 201);
    }

    public function delete(string $providerId, string $zone): Response
    {
        $zoneId = $this->zoneId($providerId, $zone);

        return ApiResponse::data(
            $this->zones->delete($providerId, $zoneId),
        );
    }

    private function zoneId(string $providerId, string $zone): string
    {
        $zone = strtolower(trim(rawurldecode($zone)));

        return $this->zones->idByName($providerId, $zone);
    }
}
