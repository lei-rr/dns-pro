<?php

declare(strict_types=1);

namespace app\service\edgeone;

use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Teo\V20220901\TeoClient;

class EdgeOneClientFactory
{
    public function make(array $dnspodProvider): TeoClient
    {
        $httpProfile = new HttpProfile();
        $httpProfile->setEndpoint((string) config('services.tencent.edgeone_endpoint'));
        $httpProfile->setReqTimeout((int) config('services.tencent.timeout'));

        $clientProfile = new ClientProfile();
        $clientProfile->setHttpProfile($httpProfile);

        return new TeoClient(
            new Credential($dnspodProvider['secret_id'], $dnspodProvider['secret_key']),
            (string) config('services.tencent.edgeone_region'),
            $clientProfile,
        );
    }
}
