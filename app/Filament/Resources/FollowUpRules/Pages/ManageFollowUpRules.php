<?php

namespace App\Filament\Resources\FollowUpRules\Pages;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\PropertyResource;
use App\FollowUps\AskProduct;
use App\FollowUps\GeneralFollowUps;
use App\FollowUps\PropertyFollowUps;
use App\FollowUps\SurveyReminder;
use App\Models\Property;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
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

        if (! GeneralFollowUps::for(app(CurrentTenant::class)->get())->applies()) {
            return "Lead dengan {$item} menerima follow-up dari halaman {$item} itu, tab Follow-up. Lead tanpa {$item} menerima pesan \"Tanya produk\".";
        }

        return "Dipakai untuk lead tanpa {$item}, dan untuk lead yang {$item}-nya belum punya follow-up sendiri di tahap itu. Follow-up khusus satu {$item} diatur di halaman {$item} itu, tab Follow-up; untuk tahap yang sama hanya satu rangkaian yang terkirim.";
    }

    /**
     * Above the table: whether the general follow-ups are used now and why (with the setting), and, when they are
     * not, the "Tanya produk" message for leads without a product.
     */
    public function content(Schema $schema): Schema
    {
        $tenant = app(CurrentTenant::class)->get();
        $general = GeneralFollowUps::for($tenant);
        $applies = $general->applies();

        return parent::content($schema)->components([
            Callout::make('Follow-up umum: '.($applies ? 'dipakai' : 'tidak dipakai'))
                ->description($general->reason().($general->mode() === 'auto' ? ' (pengaturan: Otomatis)' : ''))
                ->icon($applies ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedPauseCircle)
                ->color($applies ? 'success' : 'gray')
                ->actions([$this->generalModeAction()]),
            Callout::make('Pesan cadangan "Tanya produk"')
                ->visible(! $applies)
                ->description(fn () => $this->askSummary())
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->color('info')
                ->actions([$this->askProductAction()]),
            Callout::make('Follow-up properti bawaan')
                ->visible($tenant?->industry()->key === 'properti')
                ->description(fn () => $this->propertySummary())
                ->icon(Heroicon::OutlinedBolt)->color(fn () => PropertyFollowUps::for(app(CurrentTenant::class)->get())->enabled() ? 'success' : 'info')
                ->actions([$this->propertyFollowUpsAction()]),
            Callout::make('Pengingat survei H-1')
                ->visible($tenant?->industry()->key === 'properti')
                ->description(fn () => $this->surveySummary())
                ->icon(Heroicon::OutlinedCalendarDays)->color(fn () => SurveyReminder::orDefaults()->active ? 'success' : 'gray')
                ->actions([$this->surveyReminderAction()]),
            Callout::make('Aturan di bawah sedang tidak dikirim')
                ->visible(! $applies)
                ->description('Aturan follow-up umum tidak dihapus dan tetap bisa diubah, tetapi dilewati selama follow-up umum tidak dipakai.')
                ->color('gray'),
            ...parent::content($schema)->getComponents(),
        ]);
    }

    public function generalModeAction(): Action
    {
        return Action::make('generalMode')->label('Ubah pengaturan')->color('gray')->size('sm')->button()
            ->modalHeading('Follow-up umum')
            ->modalDescription('Toko retail dengan banyak produk sebaiknya mengatur follow-up di halaman tiap produk.')
            ->fillForm(fn () => ['general_follow_ups' => GeneralFollowUps::for(app(CurrentTenant::class)->get())->mode()])
            ->schema([
                Radio::make('general_follow_ups')->label('Follow-up umum')->required()->options(GeneralFollowUps::MODES)->descriptions([
                    'auto' => 'Dipakai untuk agensi properti dan toko dengan paling banyak 1 produk aktif; tidak dipakai untuk toko dengan lebih dari 1 produk aktif.',
                    'always' => 'Selalu dikirim ke lead yang produknya belum punya follow-up sendiri.',
                    'never' => 'Tidak pernah dikirim. Lead tanpa produk menerima pesan "Tanya produk".',
                ]),
            ])
            ->action(function (array $data) {
                app(CurrentTenant::class)->get()->update(['general_follow_ups' => $data['general_follow_ups']]);
                Notification::make()->title('Pengaturan follow-up umum disimpan')->success()->send();
            });
    }

    private function propertySummary(): string
    {
        $follow = PropertyFollowUps::for(app(CurrentTenant::class)->get());
        $names = implode('; ', [...$follow->sequences(), SurveyReminder::DEFAULTS['name']]);

        return ($follow->enabled() ? 'Semua aktif' : 'Belum semua aktif').": {$names}. "
            .'Rangkaian berhenti untuk lead yang sudah membalas atau sudah dibalas agen (dari Inbox atau HP); pengingat survei tetap dikirim.';
    }

    public function propertyFollowUpsAction(): Action
    {
        return Action::make('propertyFollowUps')->label('Nyalakan semua')->color('primary')->size('sm')->button()
            ->visible(fn () => ! PropertyFollowUps::for(app(CurrentTenant::class)->get())->enabled())
            ->requiresConfirmation()
            ->modalHeading('Nyalakan follow-up properti bawaan')
            ->modalDescription(fn () => PropertyFollowUps::for(app(CurrentTenant::class)->get())->preview())
            ->action(function () {
                $result = PropertyFollowUps::for(app(CurrentTenant::class)->get())->enable();
                Notification::make()->title(count($result['on']).' follow-up aktif')
                    ->body($result['missing'] !== [] ? 'Tidak bisa dibuat: '.implode('; ', $result['missing']) : null)
                    ->status($result['missing'] !== [] ? 'warning' : 'success')->send();
            });
    }

    private function surveySummary(): string
    {
        $rule = SurveyReminder::orDefaults();

        return ($rule->active ? 'Aktif' : 'Nonaktif').": dikirim ke klien sehari sebelum jadwal survei, jam {$rule->send_time}, sekali per jadwal. "
            .'Tidak dikirim bila survei dibatalkan, lead pindah tahap, atau jadwalnya baru dibuat setelah jam pengingat lewat.';
    }

    public function surveyReminderAction(): Action
    {
        return Action::make('surveyReminder')->label('Atur pengingat')->color('gray')->size('sm')->button()
            ->modalHeading('Pengingat survei H-1')
            ->modalDescription('{tanggal_survei}, {jam_survei}, dan {lokasi_survei} diisi dari jadwal survei lead, dalam zona waktu agensi.')
            ->fillForm(fn () => SurveyReminder::orDefaults()->only(['send_time', 'body', 'media_asset_id', 'active']))
            ->schema(collect(FollowUpRuleResource::fields())->filter(fn ($f) => ! method_exists($f, 'getName') || in_array($f->getName(), ['send_time', 'body', 'media_asset_id', 'active'], true))->values()->all())
            ->extraModalFooterActions([
                Action::make('surveyDefault')->label('Pakai teks bawaan')->color('gray')->cancelParentActions()->requiresConfirmation()
                    ->action(function () {
                        SurveyReminder::rule()->update(['body' => SurveyReminder::DEFAULT_BODY]);
                        Notification::make()->title('Teks bawaan dipakai lagi')->success()->send();
                    }),
            ])
            ->action(function (array $data) {
                SurveyReminder::rule()->update(array_intersect_key($data, array_flip(['send_time', 'body', 'media_asset_id', 'active'])));
                Notification::make()->title('Pengingat survei disimpan')->success()->send();
            });
    }

    private function askSummary(): string
    {
        $rule = AskProduct::rule();

        return ($rule->active ? 'Aktif' : 'Nonaktif').': dikirim sekali ke lead tanpa '.Industry::t('catalog_item')
            .' di tahap '.($rule->stage?->name ?? 'pertama').", H+{$rule->delay_days} jam {$rule->send_time}. "
            .'Balasan angka dari pembeli otomatis menentukan '.Industry::t('catalog_item').' lead, lalu rangkaian '.Industry::t('catalog_item').' itu berjalan.';
    }

    public function askProductAction(): Action
    {
        return Action::make('askProduct')->label('Atur pesan')->color('gray')->size('sm')->button()
            ->modalHeading('Pesan cadangan "Tanya produk"')
            ->modalDescription('{daftar_produk} diganti daftar '.Industry::t('catalog_item').' aktif bernomor, dengan harga promo bila ada.')
            ->fillForm(fn () => AskProduct::rule()->only(['name', 'stage_id', 'delay_days', 'send_time', 'body', 'media_asset_id', 'active']))
            ->schema(FollowUpRuleResource::fields())
            ->extraModalFooterActions([
                Action::make('askDefault')->label('Pakai teks bawaan')->color('gray')->cancelParentActions()->requiresConfirmation()
                    ->action(function () {
                        AskProduct::rule()->update(['body' => AskProduct::DEFAULT_BODY]);
                        Notification::make()->title('Teks bawaan dipakai lagi')->success()->send();
                    }),
            ])
            ->action(function (array $data) {
                AskProduct::rule()->update($data);
                Notification::make()->title('Pesan "Tanya produk" disimpan')->success()->send();
            });
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
