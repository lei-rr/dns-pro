<?php

declare(strict_types=1);

namespace app\service\dnspod;

use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Dnspod\V20210323\DnspodClient;

class DnsPodClientFactory
{
    public function make(array $provider): DnspodClient
    {
        $httpProfile = new HttpProfile();
        $httpProfile->setEndpoint((string) config('services.tencent.dnspod_endpoint'));
        $httpProfile->setReqTimeout((int) config('services.tencent.timeout'));

        $clientProfile = new ClientProfile();
        $clientProfile->setHttpProfile($httpProfile);

        return new DnspodClient(
            new Credential($provider['secret_id'], $provider['secret_key']),
            '',
            $clientProfile,
        );
    }
}
