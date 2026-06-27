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
        validate(ProviderValidate::class)
            ->scene('store')
            ->check(input('post.', []));

        // checked() 只返回规则声明字段，会丢弃类型专属字段（如 cloudflare_provider）；
        // 这里用原始 input 交给 service，由 ProviderNormalizer 按 definition 取字段并校验。
        $provider = $this->providers->create(input('post.', []));

        return ApiResponse::data($provider, 201);
    }

    public function update(string $id): Response
    {
        validate(ProviderValidate::class)
            ->scene('update')
            ->check(input('put.', []));

        // 同 store：用原始 input，避免 checked() 丢弃类型专属字段
        $provider = $this->providers->update($id, input('put.', []));

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
