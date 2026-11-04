<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Properties\RelationManagers\FollowUpsRelationManager;
use App\Filament\Resources\Properties\RelationManagers\KnowledgeRelationManager;
use App\Support\Industry;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * One product's page, where everything about that product is set: its details, the AI knowledge and the follow-up
 * sequence that only apply to it, and its ads and promo. The active tab is in the URL (?relation=...), so a link
 * can open a tab directly.
 */
class EditProperty extends EditRecord
{
    protected static string $resource = PropertyResource::class;

    public const KNOWLEDGE = 'knowledge';

    public const FOLLOW_UPS = 'followups';

    public const PROMO = 'promo';

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    public function content(Schema $schema): Schema
    {
        $record = $this->getRecord();
        $data = ['ownerRecord' => $record, 'pageClass' => static::class];
        $manager = fn (string $class) => $class::getTabComponent($record, static::class)
            ->schema([Livewire::make($class, $data)->key($class)])
            ->visible(fn () => $class::canViewForRecord($record, static::class));

        return $schema->components([
            Tabs::make()->key('productTabs')->livewireProperty('activeRelationManager')->contained(false)->extraAttributes(['class' => 'product-tabs'])->tabs([
                '' => Tab::make('Detail '.Industry::t('catalog_item'))->icon(Heroicon::OutlinedCube)->schema([$this->getFormContentComponent()]),
                self::KNOWLEDGE => $manager(KnowledgeRelationManager::class),
                self::FOLLOW_UPS => $manager(FollowUpsRelationManager::class),
                self::PROMO => Tab::make('Iklan & promo')->icon(Heroicon::OutlinedMegaphone)->schema([$this->getFormContentComponent()]),
            ]),
        ]);
    }
}
