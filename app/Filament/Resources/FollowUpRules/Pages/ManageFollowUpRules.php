<?php

namespace App\Filament\Resources\FollowUpRules\Pages;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Property;
use App\Support\Industry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageFollowUpRules extends ManageRecords
{
    protected static string $resource = FollowUpRuleResource::class;

    public function getTitle(): string
    {
        return 'Follow-up umum (semua '.Industry::t('catalog_item').')';
    }

    public function getSubheading(): ?string
    {
        $item = Industry::t('catalog_item');

        return "Dipakai untuk lead tanpa {$item}, dan untuk lead yang {$item}-nya belum punya follow-up sendiri di tahap itu. Follow-up khusus satu {$item} diatur di halaman {$item} itu, tab Follow-up; untuk tahap yang sama hanya satu rangkaian yang terkirim.";
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->productLinks(),
            CreateAction::make()->before(function (CreateAction $action) {
                if ($denied = PlanLimits::current()?->denyAdding('followups')) {
                    Deny::notify($denied);
                    $action->halt();
                }
            }),
        ];
    }

    /** The products that have their own follow-ups, each a link to its Follow-up tab. */
    private function productLinks(): ActionGroup
    {
        $products = Property::query()->whereHas('followUpRules')
            ->withCount(['followUpRules as active_rules' => fn ($q) => $q->where('active', true)])
            ->orderBy('name')->limit(30)->get();

        return ActionGroup::make($products->map(fn (Property $p) => Action::make('product-'.$p->getKey())
            ->label("{$p->name} ({$p->active_rules} aktif)")
            ->url(PropertyResource::getUrl('edit', ['record' => $p, 'relation' => EditProperty::FOLLOW_UPS])))->all())
            ->label('Follow-up per '.Industry::t('catalog_item').' ('.$products->count().')')
            ->icon(Heroicon::OutlinedSquares2x2)->color('gray')->button()
            ->visible($products->isNotEmpty());
    }
}
