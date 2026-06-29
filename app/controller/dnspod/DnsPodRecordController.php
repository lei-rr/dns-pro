<?php

declare(strict_types=1);

namespace app\controller\dnspod;

use app\controller\concerns\ValidatesInput;
use app\service\dnspod\DnsPodRecordGateway;
use app\support\ApiResponse;
use app\validate\DnsPodRecordValidate;
use think\Response;

class DnsPodRecordController
{
    use ValidatesInput;

    public function __construct(
        private readonly DnsPodRecordGateway $records,
    ) {
    }

    public function index(string $providerId, string $zone): Response
    {
        $filters = $this->queryInput(DnsPodRecordValidate::class, 'index');

        return ApiResponse::data($this->records->list($providerId, $zone, $filters));
    }

    public function store(string $providerId, string $zone): Response
    {
        $data = $this->postInput(DnsPodRecordValidate::class, 'record');

        return ApiResponse::data($this->records->create($providerId, $zone, $data), 201);
    }

    public function update(string $providerId, string $zone, string $recordId): Response
    {
        $data = $this->putInput(DnsPodRecordValidate::class, 'record');

        return ApiResponse::data($this->records->update($providerId, $zone, $recordId, $data));
    }

    public function delete(string $providerId, string $zone, string $recordId): Response
    {
        return ApiResponse::data($this->records->delete($providerId, $zone, $recordId));
    }
}
