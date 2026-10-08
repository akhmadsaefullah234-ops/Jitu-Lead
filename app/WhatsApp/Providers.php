<?php

namespace App\WhatsApp;

use App\Enums\WaChannelType;
use App\Models\WaChannel;

class Providers
{
    public function for(WaChannel $channel): WhatsAppProvider
    {
        return match ($channel->type) {
            WaChannelType::Official => new CloudApiProvider($channel),
            WaChannelType::Gateway => new GatewayProvider($channel),
        };
    }
}
