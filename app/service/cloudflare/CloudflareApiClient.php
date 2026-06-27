<?php

declare(strict_types=1);

namespace app\service\cloudflare;

use app\exception\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CloudflareApiClient
{
    /**
     * 客户端实例缓存
     */
    private ?Client $client = null;

    /**
     * 获取或创建HTTP客户端
     */
    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'base_uri' => config('services.cloudflare.base_uri'),
                'timeout' => config('services.cloudflare.timeout'),
                'http_errors' => false,
            ]);
        }

        return $this->client;
    }

    public function get(array $provider, string $path, array $query = []): array
    {
        return $this->request($provider, 'GET', $path, ['query' => $query]);
    }

    public function post(array $provider, string $path, array $json = []): array
    {
        return $this->request($provider, 'POST', $path, ['json' => $json]);
    }

    public function put(array $provider, string $path, array $json = []): array
    {
        return $this->request($provider, 'PUT', $path, ['json' => $json]);
    }

    public function patch(array $provider, string $path, array $json = []): array
    {
        return $this->request($provider, 'PATCH', $path, ['json' => $json]);
    }

    public function delete(array $provider, string $path): array
    {
        return $this->request($provider, 'DELETE', $path);
    }

    private function request(array $provider, string $method, string $path, array $options = []): array
    {
        $client = $this->getClient();

        try {
            $options['headers'] = [
                ...($options['headers'] ?? []),
                'Authorization' => 'Bearer ' . $provider['api_token'],
                'Accept' => 'application/json',
            ];

            $response = $client->request($method, $path, $options);
        } catch (GuzzleException) {
            throw new ApiException('Cloudflare connection failed', 502, 'cloudflare_connection_failed', [
                'provider_id' => $provider['id'] ?? null,
            ]);
        }

        $payload = json_decode((string) $response->getBody(), true);

        if (!is_array($payload)) {
            throw new ApiException('Invalid Cloudflare response', 502, 'cloudflare_invalid_response', [
                'provider_id' => $provider['id'] ?? null,
                'http_status' => $response->getStatusCode(),
            ]);
        }

        if (($payload['success'] ?? false) !== true) {
            $errors = $payload['errors'] ?? [];
            $firstMessage = '';
            if (is_array($errors) && isset($errors[0]['message'])) {
                $firstMessage = (string) $errors[0]['message'];
            }

            $detail = $firstMessage !== '' ? 'Cloudflare request failed: ' . $firstMessage : 'Cloudflare request failed';

            throw new ApiException($detail, 502, 'cloudflare_request_failed', [
                'provider_id' => $provider['id'] ?? null,
                'http_status' => $response->getStatusCode(),
                'errors' => $errors,
            ]);
        }

        return $payload;
    }
}
