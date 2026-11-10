<?php

namespace App\Shipping\Shipments;

final class ProviderLocations
{
    public const EXACT = 'exact';

    public const MULTIPLE = 'multiple';

    public const NONE = 'none';

    public static function chip(\App\Models\Lead $lead): ?string
    {
        return null;
    }
}
