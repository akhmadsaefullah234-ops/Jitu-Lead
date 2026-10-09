<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

trait VisibleLeads
{
    /**
     * Leads the signed-in person may see: all for admins and team leaders,
     * only their own for agents.
     */
    protected function leads(): Builder
    {
        return LeadResource::onlyVisibleLeads(Lead::query());
    }
}
