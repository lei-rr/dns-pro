<?php

declare(strict_types=1);

namespace app\controller\dnspod;

use app\controller\concerns\ValidatesInput;
use app\service\dnspod\DnsPodZoneGateway;
use app\support\ApiResponse;
use app\validate\DnsPodZoneValidate;
use think\Response;

class DnsPodZoneController
{
    use ValidatesInput;

    public function __construct(
        private readonly DnsPodZoneGateway $zones,
    ) {
    }

    public function index(string $providerId): Response
    {
        $query = $this->queryInput(DnsPodZoneValidate::class, 'index');

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
        $data = $this->postInput(DnsPodZoneValidate::class, 'store');

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
