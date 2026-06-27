<?php

declare(strict_types=1);

namespace app\controller\dnspod;

use app\service\dnspod\DnsPodRecordService;
use app\support\ApiResponse;
use app\validate\DnsPodRecordValidate;
use think\Response;

class DnsPodRecordController
{
    public function __construct(
        private readonly DnsPodRecordService $records,
    ) {
    }

    public function index(string $providerId, string $zone): Response
    {
        $filters = validate(DnsPodRecordValidate::class)
            ->scene('index')
            ->checked(input('get.', []));

        return ApiResponse::data($this->records->list($providerId, $zone, $filters));
    }

    public function store(string $providerId, string $zone): Response
    {
        $data = validate(DnsPodRecordValidate::class)
            ->scene('record')
            ->checked(input('post.', []));

        return ApiResponse::data($this->records->create($providerId, $zone, $data), 201);
    }

    public function update(string $providerId, string $zone, string $recordId): Response
    {
        $data = validate(DnsPodRecordValidate::class)
            ->scene('record')
            ->checked(input('put.', []));

        return ApiResponse::data($this->records->update($providerId, $zone, $recordId, $data));
    }

    public function delete(string $providerId, string $zone, string $recordId): Response
    {
        return ApiResponse::data($this->records->delete($providerId, $zone, $recordId));
    }
}
