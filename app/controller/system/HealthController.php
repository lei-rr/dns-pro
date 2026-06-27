<?php

declare(strict_types=1);

namespace app\controller\system;

use app\support\ApiResponse;
use think\Response;

class HealthController
{
    public function show(): Response
    {
        return ApiResponse::data(['ok' => true]);
    }
}
