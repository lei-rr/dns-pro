<?php

declare(strict_types=1);

namespace app\controller\edgeone;

use app\controller\concerns\ValidatesInput;
use app\service\edgeone\EdgeOneService;
use app\support\ApiResponse;
use app\validate\EdgeOneZoneValidate;
use think\Response;

class EdgeOneZoneController
{
    use ValidatesInput;

    public function __construct(private readonly EdgeOneService $edgeone)
    {
    }

    public function index(string $providerId): Response
    {
        $query = $this->queryInput(EdgeOneZoneValidate::class, 'index');

        return ApiResponse::data($this->edgeone->zones($providerId, [
            'offset' => (int) ($query['offset'] ?? 0),
            'limit' => (int) ($query['limit'] ?? 20),
            'refresh' => filter_var($query['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]));
    }

    public function show(string $providerId, string $zoneId): Response
    {
        return ApiResponse::data($this->edgeone->zoneById($providerId, $zoneId));
    }
}
