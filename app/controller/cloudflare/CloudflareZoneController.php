<?php

declare(strict_types=1);

namespace app\controller\cloudflare;

use app\service\cloudflare\CloudflareZoneService;
use app\support\ApiResponse;
use app\validate\CloudflareZoneValidate;
use think\Response;

class CloudflareZoneController
{
    public function __construct(
        private readonly CloudflareZoneService $zones,
    ) {
    }

    public function index(string $providerId): Response
    {
        $query = validate(CloudflareZoneValidate::class)
            ->scene('index')
            ->checked(input('get.', []));

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
        $data = validate(CloudflareZoneValidate::class)
            ->scene('store')
            ->checked(input('post.', []));

        $result = $this->zones->create(
            $providerId,
            strtolower(trim((string) $data['name'])),
            (string) ($data['type'] ?? 'full'),
        );

        return ApiResponse::data($result, 201);
    }

    public function delete(string $providerId, string $zone): Response
    {
        $zone = strtolower(trim(rawurldecode($zone)));

        return ApiResponse::data(
            $this->zones->delete($providerId, $this->zones->idByName($providerId, $zone)),
        );
    }
}
