<?php

declare(strict_types=1);

namespace app\controller\dnspod;

use app\service\dnspod\DnsPodZoneService;
use app\support\ApiResponse;
use app\validate\DnsPodZoneValidate;
use think\Response;

class DnsPodZoneController
{
    public function __construct(
        private readonly DnsPodZoneService $zones,
    ) {
    }

    public function index(string $providerId): Response
    {
        $query = validate(DnsPodZoneValidate::class)
            ->scene('index')
            ->checked(input('get.', []));

        return ApiResponse::data($this->zones->list(
            $providerId,
            (int) ($query['offset'] ?? 0),
            (int) ($query['limit'] ?? 20),
            trim((string) ($query['keyword'] ?? '')),
            filter_var($query['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ));
    }

    public function store(string $providerId): Response
    {
        $data = validate(DnsPodZoneValidate::class)
            ->scene('store')
            ->checked(input('post.', []));

        return ApiResponse::data(
            $this->zones->create($providerId, strtolower(trim((string) $data['domain']))),
            201,
        );
    }

    public function delete(string $providerId, string $zone): Response
    {
        return ApiResponse::data($this->zones->delete($providerId, $zone));
    }
}
