<?php

namespace App\Filament\Admin\Resources\SubscriptionRequests\Pages;

use App\Filament\Admin\Resources\SubscriptionRequests\SubscriptionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionRequests extends ListRecords
{
    protected static string $resource = SubscriptionRequestResource::class;
}
