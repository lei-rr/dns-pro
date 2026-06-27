<?php

declare(strict_types=1);

namespace app\controller\provider;

use app\exception\ApiException;
use app\service\provider\ProviderService;
use app\support\ApiResponse;
use app\validate\ProviderValidate;
use think\Response;

class ProviderController
{
    public function __construct(private readonly ProviderService $providers)
    {
    }

    public function definitions(): Response
    {
        return ApiResponse::data($this->providers->definitions());
    }

    public function index(): Response
    {
        return ApiResponse::data($this->providers->all());
    }

    public function show(string $id): Response
    {
        $provider = $this->providers->find($id);

        if (!$provider) {
            throw new ApiException('Provider not found', 404, 'provider_not_found');
        }

        return ApiResponse::data($provider);
    }

    public function store(): Response
    {
        $data = validate(ProviderValidate::class)
            ->scene('store')
            ->checked(input('post.', []));

        $provider = $this->providers->create($data);

        return ApiResponse::data($provider, 201);
    }

    public function update(string $id): Response
    {
        $data = validate(ProviderValidate::class)
            ->scene('update')
            ->checked(input('put.', []));

        $provider = $this->providers->update($id, $data);

        return ApiResponse::data($provider);
    }

    public function delete(string $id): Response
    {
        $this->providers->delete($id);

        return ApiResponse::noContent();
    }

    public function sort(): Response
    {
        $input = validate(ProviderValidate::class)
            ->scene('sort')
            ->checked(input('put.', []));

        $providers = $this->providers->sort($input['order']);

        return ApiResponse::data($providers);
    }
}
