<?php

declare(strict_types=1);

namespace app\controller\edgeone;

use app\service\edgeone\EdgeOneService;
use app\support\ApiResponse;
use app\validate\EdgeOneZoneValidate;
use think\Response;

class EdgeOneZoneController
{
    public function __construct(private readonly EdgeOneService $edgeone)
    {
    }

    public function index(string $providerId): Response
    {
        $query = validate(EdgeOneZoneValidate::class)->scene('index')->checked(input('get.', []));

        return ApiResponse::data($this->edgeone->zones($providerId, [
            'offset' => (int) ($query['offset'] ?? 0),
            'limit' => (int) ($query['limit'] ?? 100),
            'refresh' => filter_var($query['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]));
    }
}
