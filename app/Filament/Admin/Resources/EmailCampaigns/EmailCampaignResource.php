<?php

namespace App\Filament\Admin\Resources\EmailCampaigns;

use App\Campaigns\CampaignAudience;
use App\Campaigns\CampaignMail;
use App\Campaigns\CampaignRunner;
use App\Filament\Admin\Resources\EmailCampaigns\Pages\ManageEmailCampaigns;
use App\Models\EmailCampaign;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;

/** Promo email series written and started by hand. Sent by campaigns:send, within the daily cap shared with the trial emails. */
class EmailCampaignResource extends Resource
{
    protected static ?string $model = EmailCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $modelLabel = 'kampanye email';

    protected static ?string $pluralModelLabel = 'Kampanye email';

    protected static ?string $navigationLabel = 'Kampanye email';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama kampanye')->required()->maxLength(120)->columnSpanFull(),
            Select::make('segment')->label('Penerima')->options(EmailCampaign::SEGMENTS)->required()->native(false)->live()
                ->default('trial_active')
                ->afterStateUpdated(fn ($state, Set $set) => $set('stop_when_subscribed', in_array($state, ['trial_active', 'trial_expired'], true))),
            Placeholder::make('audience')->label('Perkiraan penerima saat ini')
                ->content(fn (Get $get) => filled($get('segment')) ? number_format(CampaignAudience::count($get('segment')), 0, ',', '.').' orang' : '-')
                ->helperText('Hanya yang bersedia dihubungi, emailnya terverifikasi, dan belum berhenti berlangganan. Daftar penerima email pertama dikunci saat kampanye mulai.'),
            DateTimePicker::make('starts_at')->label('Mulai (WIB)')->required()->native(false)->seconds(false)
                ->timezone('Asia/Jakarta')->default(now()->addDay()->setTime(9, 0)),
            TextInput::make('interval_days')->label('Jeda antaremail (hari)')->numeric()->integer()->minValue(1)->maxValue(60)->default(3)->required(),
            Toggle::make('stop_when_subscribed')->label('Berhenti bila penerima sudah berlangganan')->default(true)->columnSpanFull(),
            Repeater::make('steps')->label('Email dalam seri')->columnSpanFull()
                ->minItems(1)->maxItems(EmailCampaign::MAX_STEPS)->defaultItems(1)->reorderable()->collapsible()
                ->itemLabel(fn (array $state) => $state['subject'] ?? null)
                ->addActionLabel('Tambah email')
                ->schema([
                    TextInput::make('subject')->label('Judul (subjek)')->required()->maxLength(160),
                    MarkdownEditor::make('body')->label('Isi')->required()->maxLength(8000)
                        ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                        ->helperText('Bisa memakai '.implode(' ', CampaignMail::PLACEHOLDERS).'.'),
                    TextInput::make('button')->label('Tulisan tombol (opsional)')->maxLength(60),
                    TextInput::make('url')->label('Tautan tombol')->url()->rule('regex:#^https?://#i')->maxLength(500)
                        ->requiredWith('button')->placeholder('https://...'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('name')->label('Kampanye')->searchable()
                ->description(fn (EmailCampaign $c) => count($c->emails()).' email, jeda '.$c->interval_days.' hari'),
            TextColumn::make('segment')->label('Penerima')->formatStateUsing(fn (string $state) => EmailCampaign::SEGMENTS[$state] ?? $state)
                ->description(fn (EmailCampaign $c) => $c->audience_frozen_at ? null : 'Perkiraan '.CampaignAudience::count($c->segment).' orang'),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state) => EmailCampaign::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    EmailCampaign::RUNNING => 'success', EmailCampaign::PAUSED => 'warning', EmailCampaign::STOPPED => 'danger', default => 'gray',
                }),
            TextColumn::make('starts_at')->label('Mulai')->dateTime('d M Y H:i', 'Asia/Jakarta')->sortable(),
            TextColumn::make('sent')->label('Terkirim')->state(fn (EmailCampaign $c) => $c->stats()['sent']),
            TextColumn::make('failed')->label('Gagal')->state(fn (EmailCampaign $c) => $c->stats()['failed']),
            TextColumn::make('unsubscribed')->label('Berhenti berlangganan')->state(fn (EmailCampaign $c) => $c->stats()['unsubscribed']),
            TextColumn::make('waiting')->label('Menunggu')->state(fn (EmailCampaign $c) => $c->stats()['waiting'])->toggleable(isToggledHiddenByDefault: true),
        ])->recordActions([
            Action::make('run')->label('Jalankan')->icon(Heroicon::OutlinedPlay)->color('success')
                ->visible(fn (EmailCampaign $c) => in_array($c->status, [EmailCampaign::DRAFT, EmailCampaign::PAUSED], true))
                ->requiresConfirmation()
                ->modalDescription(fn (EmailCampaign $c) => $c->status === EmailCampaign::PAUSED
                    ? 'Pengiriman dilanjutkan dari email yang tertunda.'
                    : 'Email pertama dikirim mulai '.$c->starts_at->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB ke sekitar '.CampaignAudience::count($c->segment).' orang.')
                ->action(function (EmailCampaign $record) {
                    $record->update(['status' => EmailCampaign::RUNNING]);
                    Notification::make()->title('Kampanye berjalan')->success()->send();
                }),
            Action::make('pause')->label('Jeda')->icon(Heroicon::OutlinedPause)->color('warning')
                ->visible(fn (EmailCampaign $c) => $c->status === EmailCampaign::RUNNING)
                ->action(fn (EmailCampaign $record) => $record->update(['status' => EmailCampaign::PAUSED])),
            Action::make('stop')->label('Hentikan')->icon(Heroicon::OutlinedStop)->color('danger')
                ->visible(fn (EmailCampaign $c) => in_array($c->status, [EmailCampaign::RUNNING, EmailCampaign::PAUSED], true))
                ->requiresConfirmation()->modalDescription('Email yang belum terkirim dibatalkan dan kampanye tidak bisa dijalankan lagi.')
                ->action(fn (EmailCampaign $record) => CampaignRunner::stop($record)),
            ActionGroup::make([
                Action::make('test')->label('Kirim uji ke saya')->icon(Heroicon::OutlinedPaperAirplane)
                    ->action(function (EmailCampaign $record) {
                        $me = auth()->user();
                        foreach (array_keys($record->emails()) as $step) {
                            Mail::to($me->email)->sendNow(CampaignMail::make($record, $step, $me, test: true));
                        }
                        Notification::make()->title(count($record->emails()).' email uji dikirim ke '.$me->email)->success()->send();
                    }),
                EditAction::make()->slideOver()
                    ->visible(fn (EmailCampaign $c) => in_array($c->status, [EmailCampaign::DRAFT, EmailCampaign::PAUSED], true)),
                DeleteAction::make()->visible(fn (EmailCampaign $c) => $c->status === EmailCampaign::DRAFT),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmailCampaigns::route('/')];
    }
}
