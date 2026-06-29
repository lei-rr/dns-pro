<?php

declare(strict_types=1);

namespace app\controller\provider;

use app\controller\concerns\ValidatesInput;
use app\exception\ApiException;
use app\service\provider\ProviderService;
use app\support\ApiResponse;
use app\validate\ProviderValidate;
use think\Response;

class ProviderController
{
    use ValidatesInput;

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
        $provider = $this->providers->create($this->rawPostInput(ProviderValidate::class, 'store'));

        return ApiResponse::data($provider, 201);
    }

    public function update(string $id): Response
    {
        $provider = $this->providers->update($id, $this->rawPutInput(ProviderValidate::class, 'update'));

        return ApiResponse::data($provider);
    }

    public function delete(string $id): Response
    {
        $this->providers->delete($id);

        return ApiResponse::noContent();
    }

    public function sort(): Response
    {
        $input = $this->putInput(ProviderValidate::class, 'sort');

        $providers = $this->providers->sort($input['order']);

        return ApiResponse::data($providers);
    }
}
