<?php

namespace App\Filament\Support;

use App\Automation\RetailStages;
use App\Leads\ChatShippingDetails;
use App\Models\Lead;
use App\Models\Shipment;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\LabelSheet;
use App\Shipping\Shipments\ShipmentDraft;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * "Buat resi", "Cetak label", "Batalkan kiriman" and "Lacak paket", the same in the Inbox ⋯ menu and on the
 * lead page, plus the bulk actions on the lead list (stage Dikemas & dikirim). All hidden while the agency has no
 * provider configured (Pengaturan > Pengiriman). They only talk to App\Shipping\Shipments.
 */
class ShipmentActions
{
    /** @var array<string, ShipmentDraft> one preview per lead and choice for one request */
    private static array $drafts = [];

    public static function available(): bool
    {
        return Carriers::configured(app(CurrentTenant::class)->get());
    }

    /** Address fields of the "Buat resi" form => the lead's address part they edit (null: the address text, the name). */
    private const ADDRESS_FIELDS = [
        'addr_name' => 'name', 'addr_text' => 'shipping_address', 'addr_rt_rw' => 'rt_rw', 'addr_landmark' => 'landmark',
        'addr_kelurahan' => 'kelurahan', 'addr_kecamatan' => 'kecamatan', 'addr_kota' => 'kota', 'addr_kode_pos' => 'kode_pos',
    ];

    /** @param  array<string, mixed>  $address  the address fields of the form, when shown */
    private static function draft(Lead $lead, ?string $location, ?string $rate, array $address = [], ?string $payment = null): ShipmentDraft
    {
        $lead = self::withAddress($lead, $address);
        $key = $lead->getKey().'|'.$location.'|'.$rate.'|'.$payment.'|'.md5(json_encode([$lead->name, $lead->shipping_address, $lead->shipping_address_parts]));

        return self::$drafts[$key] ??= app(Shipments::class)->draft($lead, ['location_id' => $location, 'rate_code' => $rate, 'payment' => $payment]);
    }

    /** The lead's way of paying is unknown or only read from the chat: the agent picks before booking. */
    private static function asksPayment(Lead $lead): bool
    {
        return Shipments::payment($lead) === null;
    }

    /** The way of paying the agent picked, onto the lead (confirmed). */
    private static function savePayment(Lead $lead, ?string $payment): void
    {
        if (! isset(Shipments::PAYMENTS[$payment]) || ($lead->payment_type === $payment && $lead->payment_type_source === null)) {
            return;
        }

        $lead->forceFill(['payment_type' => $payment, 'payment_type_source' => null])->save();
        $lead->activities()->create(['user_id' => auth()->id(), 'type' => 'note', 'body' => 'Cara bayar dipastikan sebelum membuat resi: '.Shipments::PAYMENTS[$payment].'.']);
    }

    /** The lead as the form has it (not saved). */
    private static function withAddress(Lead $lead, array $address): Lead
    {
        $address = array_intersect_key($address, self::ADDRESS_FIELDS);

        if ($address === []) {
            return $lead;
        }

        $copy = clone $lead;
        $parts = (array) $lead->shipping_address_parts;

        foreach ($address as $field => $value) {
            $value = trim((string) $value);

            match ($target = self::ADDRESS_FIELDS[$field]) {
                'name', 'shipping_address' => $copy->{$target} = $value !== '' ? $value : null,
                default => $parts[$target] = $value,
            };
        }

        $copy->shipping_address_parts = array_filter($parts, fn ($v) => $v !== '' && $v !== null) ?: null;

        return $copy;
    }

    /** @return array<string, ?string> the form's address fields from a lead */
    private static function addressState(Lead $lead): array
    {
        $parts = (array) $lead->shipping_address_parts;

        return collect(self::ADDRESS_FIELDS)->map(fn (string $target) => match ($target) {
            'name', 'shipping_address' => $lead->{$target},
            default => $parts[$target] ?? null,
        })->all();
    }

    private static function needsAddress(Lead $lead): bool
    {
        return ! ChatShippingDetails::complete($lead) || array_intersect(app(Shipments::class)->leadProblems($lead), [
            'Nama penerima belum diisi.', 'Alamat kirim lengkap belum diisi.', 'Kecamatan atau kode pos tujuan belum diisi di alamat lead.',
        ]) !== [];
    }

    /** The address as edited in the form, onto the lead, before booking. */
    private static function saveAddress(Lead $lead, array $data): void
    {
        $edited = self::withAddress($lead, $data);
        $changes = array_filter([
            'name' => $edited->name !== $lead->name ? $edited->name : null,
            'shipping_address' => $edited->shipping_address !== $lead->shipping_address ? $edited->shipping_address : null,
            'shipping_address_parts' => $edited->shipping_address_parts != $lead->shipping_address_parts ? $edited->shipping_address_parts : null,
        ], fn ($v) => $v !== null);

        if ($changes === []) {
            return;
        }

        $lead->forceFill($changes)->save();
        $lead->activities()->create(['user_id' => auth()->id(), 'type' => 'note', 'body' => 'Alamat kirim diubah sebelum membuat resi.', 'meta' => ['fields' => array_keys($changes)]]);
    }

    /** @param  Closure(): ?Lead  $lead */
    public static function create(Closure $lead, string $name = 'createShipment'): Action
    {
        $payment = fn (Get $get) => self::asksPayment($lead()) ? $get('payment') : null;
        $address = fn (Get $get) => self::needsAddress($lead()) ? array_map(fn ($f) => $get($f), array_combine(array_keys(self::ADDRESS_FIELDS), array_keys(self::ADDRESS_FIELDS))) : [];
        $field = fn (string $name, string $label) => TextInput::make($name)->label($label)->live(onBlur: true)->maxLength(150)->default(fn () => self::addressState($lead())[$name] ?? null);

        return Action::make($name)->label('Buat resi')->icon(Heroicon::OutlinedTruck)->color('gray')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && ! $l->trashed() && Shipment::latestFor($l) === null)
            ->modalHeading('Buat resi otomatis')
            ->modalDescription('Periksa dulu sebelum dikirim ke penyedia pengiriman. Resi yang terbit dikirim ke pembeli lewat WhatsApp seperti biasa.')
            ->modalSubmitActionLabel('Buat resi')
            // Nothing is booked until the agent has said how the buyer pays, and checked an unconfirmed transfer.
            ->modalSubmitAction(fn (Action $submit, Action $action) => $submit->disabled(function () use ($action, $lead) {
                $data = $action->getRawData();
                $chosen = Shipments::payment($lead(), $data['payment'] ?? null);

                return $chosen === null || (Shipments::unpaid($lead(), $chosen) && ! ($data['transfer_checked'] ?? false));
            }))
            ->schema([
                Radio::make('payment')->label('Cara bayar')->options(Shipments::PAYMENTS)->inline()->live()->required()->default(null)
                    ->visible(fn () => self::asksPayment($lead()))
                    ->helperText(fn () => ($s = app(Shipments::class)->paymentSuggestion($lead())) !== null
                        ? 'Saran dari chat: '.(Shipments::PAYMENTS[$s] ?? $s).'. Pastikan dulu, lalu pilih.'
                        : 'Cara bayar lead ini belum diketahui. Pilih sesuai kesepakatan dengan pembeli.'),
                Checkbox::make('transfer_checked')->label('Saya sudah memastikan transfer pembeli masuk')->accepted()
                    ->visible(fn (Get $get) => Shipments::unpaid($lead(), Shipments::payment($lead(), $get('payment'))))
                    ->helperText('⚠ Pembayaran transfer belum dikonfirmasi (belum ada bukti bayar atau tanda lunas di lead). Paket dikirim tanpa COD.')->live(),
                Section::make('Alamat penerima')->description('Alamat lead belum lengkap. Ambil dari chat atau isi di sini; disimpan ke lead saat resi dibuat.')
                    ->visible(fn () => self::needsAddress($lead()))
                    ->columns(2)
                    ->schema([
                        Actions::make([
                            Action::make('fromChat')->label('Ambil dari chat')->icon(Heroicon::OutlinedChatBubbleLeftRight)->link()
                                ->action(function (Set $set) use ($lead) {
                                    $record = $lead();
                                    $found = app(ChatShippingDetails::class)->fromConversation($record, save: false);
                                    $copy = clone $record;
                                    $copy->forceFill($found['changes'] ?? []);

                                    foreach (self::addressState($copy) as $f => $value) {
                                        $set($f, $value);
                                    }

                                    $set('location_id', null);
                                    Notification::make()->title(($found['labels'] ?? []) === [] ? 'Tidak ada data alamat baru di chat.' : 'Dari chat: '.implode(', ', $found['labels']).'. Periksa lalu buat resi.')
                                        ->{($found['labels'] ?? []) === [] ? 'warning' : 'success'}()->send();
                                }),
                        ])->key('addressActions')->columnSpanFull(),
                        $field('addr_name', 'Nama penerima'),
                        $field('addr_rt_rw', 'RT/RW'),
                        Textarea::make('addr_text')->label('Alamat lengkap')->rows(2)->live(onBlur: true)->maxLength(1000)->columnSpanFull()->default(fn () => self::addressState($lead())['addr_text'] ?? null),
                        $field('addr_landmark', 'Patokan'),
                        $field('addr_kelurahan', 'Kelurahan / desa'),
                        $field('addr_kecamatan', 'Kecamatan'),
                        $field('addr_kota', 'Kota / kabupaten'),
                        $field('addr_kode_pos', 'Kode pos')->maxLength(5)->rule('nullable')->rule('regex:/^\d{5}$/'),
                    ]),
                Select::make('location_id')->label('Kelurahan tujuan')->live()
                    ->options(fn (Get $get) => collect(self::draft($lead(), null, null, $address($get), $payment($get))->candidates)->mapWithKeys(fn (CarrierLocation $l) => [$l->id => $l->label()])->all())
                    ->default(fn () => self::draft($lead(), null, null)->destination?->id)
                    ->visible(fn (Get $get) => count(($d = self::draft($lead(), null, null, $address($get), $payment($get)))->candidates) > 1 || ($d->destination === null && $d->candidates !== [])),
                Select::make('rate_code')->label('Kurir dan layanan')->live()
                    ->options(fn (Get $get) => self::draft($lead(), $get('location_id'), $get('rate_code'), $address($get), $payment($get))->choices())
                    ->default(fn () => self::draft($lead(), null, null)->rate?->key())
                    ->visible(fn (Get $get) => self::draft($lead(), $get('location_id'), null, $address($get), $payment($get))->rates !== []),
                Text::make(fn (Get $get) => self::preview(self::draft($lead(), $get('location_id'), $get('rate_code'), $address($get), $payment($get))))->columnSpanFull(),
            ])
            ->action(function (array $data, Action $action) use ($lead) {
                $record = $lead();
                $choice = ['location_id' => $data['location_id'] ?? null, 'rate_code' => $data['rate_code'] ?? null, 'payment' => self::asksPayment($record) ? ($data['payment'] ?? null) : null];
                $draft = app(Shipments::class)->draft(self::withAddress($record, $data), $choice);

                if (! $draft->ok() || ($draft->unpaid && ! ($data['transfer_checked'] ?? false))) {
                    Notification::make()->title('Resi belum bisa dibuat')
                        ->body($draft->ok() ? 'Pembayaran transfer belum dikonfirmasi: pastikan transfer masuk dulu.' : implode(' ', $draft->problems))->danger()->send();
                    $action->halt();
                }

                self::savePayment($record, $choice['payment']);
                self::saveAddress($record, $data);

                try {
                    [$shipment, $new] = app(Shipments::class)->book($record, $draft, auth()->user());
                } catch (CarrierException $e) {
                    Notification::make()->title('Resi belum bisa dibuat')->body($e->getMessage())->danger()->send();

                    return;
                }

                $shipment->refresh();
                Notification::make()->title(match (true) {
                    ! $new => 'Kiriman ini sudah dibuat sebelumnya ('.($shipment->awb ?: $shipment->client_order_no).').',
                    $shipment->state === Shipment::SHIPPED => 'Resi '.$shipment->awb.' terbit.',
                    $shipment->state === Shipment::FAILED => 'Kiriman gagal dibuat: '.$shipment->error,
                    default => 'Kiriman sedang dibuat. Resi muncul di lead begitu terbit.',
                })->{$shipment->state === Shipment::FAILED ? 'danger' : 'success'}()->send();
            });
    }

    public static function preview(ShipmentDraft $draft): HtmlString
    {
        if ($draft->problems !== []) {
            return new HtmlString('<div style="color:var(--danger-600);display:grid;gap:.25rem">'.implode('', array_map(fn ($p) => '<div>• '.e($p).'</div>', $draft->problems)).'</div>');
        }

        return new HtmlString('<div style="white-space:pre-line;padding:.6rem .8rem;border-radius:.5rem;background:var(--gray-50);border:1px solid var(--gray-200)">'.e($draft->summary()).'</div>');
    }

    /**
     * "Hubungkan resi": a tracking number made outside JITU LEAD (the provider's own app) for this lead, tracked from
     * now on. The buyer gets the number only when asked for; statuses from before the link are never sent.
     *
     * @param  Closure(): ?Lead  $lead
     */
    public static function link(Closure $lead, string $name = 'linkShipment'): Action
    {
        return Action::make($name)->label('Hubungkan resi')->icon(Heroicon::OutlinedLink)->color('gray')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && ! $l->trashed() && blank(Shipment::latestFor($l)?->awb))
            ->modalHeading('Hubungkan resi yang sudah ada')
            ->modalDescription('Untuk resi yang dibuat di luar JITU LEAD, mis. di aplikasi Everpro. Resi ikut dilacak; pembeli hanya mendapat pesan untuk perubahan status setelah ini.')
            ->modalSubmitActionLabel('Hubungkan')
            ->schema([
                TextInput::make('awb')->label('Nomor resi')->required()->maxLength(40)
                    ->rule(fn () => function (string $attribute, $value, Closure $fail) use ($lead) {
                        $awb = Shipments::normalizeAwb($value);

                        if ($problem = Shipments::awbProblem($awb)) {
                            $fail($problem);
                        } elseif ($other = Shipments::awbOwner($awb, $lead())) {
                            $fail('Resi ini sudah dipakai lead lain ('.$other->name.').');
                        }
                    }),
                TextInput::make('courier')->label('Kurir')->default('JNE')->required()->maxLength(60)->datalist(['JNE', 'J&T', 'SiCepat', 'AnterAja', 'Ninja', 'ID Express', 'SAP', 'Lion Parcel', 'POS']),
                Toggle::make('notify')->label('Kirim nomor resi ke pembeli sekarang')->default(false)
                    ->helperText('Biasanya CS sudah mengirim resi manual. Bila mati, pembeli tidak dikirimi pesan resi.'),
                Toggle::make('close_delivered')->label('Bila paket sudah diterima, pindahkan lead ke Selesai')->default(true)
                    ->helperText('Tanpa pesan ke pembeli.'),
            ])
            ->action(function (array $data) use ($lead) {
                try {
                    [, $said] = app(Shipments::class)->link($lead(), $data['awb'], $data['courier'], auth()->user(), (bool) $data['notify'], (bool) $data['close_delivered']);
                } catch (CarrierException $e) {
                    Notification::make()->title('Resi belum terhubung')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Resi terhubung')->body($said)->{str_contains($said, 'belum') ? 'warning' : 'success'}()->send();
            });
    }

    /** @param  Closure(): ?Lead  $lead */
    public static function label(Closure $lead, string $name = 'shipmentLabel'): Action
    {
        return Action::make($name)->label('Cetak label')->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && filled(Shipment::latestFor($l)?->awb))
            ->action(function ($livewire) use ($lead) {
                try {
                    $url = app(Shipments::class)->label(Shipment::latestFor($lead()));
                } catch (CarrierException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }

    /** @param  Closure(): ?Lead  $lead */
    public static function cancel(Closure $lead, string $name = 'cancelShipment'): Action
    {
        return Action::make($name)->label('Batalkan kiriman')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && (Shipment::latestFor($l)?->cancellable() ?? false))
            ->requiresConfirmation()->modalHeading('Batalkan kiriman?')
            ->modalDescription('Hanya bisa sebelum paket diambil kurir. Resi lama tidak berlaku lagi; buat resi baru bila perlu.')
            ->action(function () use ($lead) {
                try {
                    app(Shipments::class)->cancel(Shipment::latestFor($lead()), auth()->user());
                } catch (CarrierException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Kiriman dibatalkan')->success()->send();
            });
    }

    /** @param  Closure(): ?Lead  $lead */
    public static function track(Closure $lead, string $name = 'trackShipment'): Action
    {
        return Action::make($name)->label('Lacak paket')->icon(Heroicon::OutlinedMapPin)->color('gray')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && filled(Shipment::latestFor($l)?->awb))
            ->modalHeading('Perjalanan paket')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
            ->mountUsing(function () use ($lead) {
                $shipment = Shipment::latestFor($lead());

                if ($shipment && ! $shipment->isFinal() && ($shipment->tracked_at === null || $shipment->tracked_at->lt(now()->subMinutes(10)))) {
                    try {
                        app(Shipments::class)->refresh($shipment);
                    } catch (CarrierException $e) {
                        Notification::make()->title($e->getMessage())->warning()->send();
                    }
                }
            })
            ->schema([Text::make(fn () => ShipmentTimeline::html(Shipment::latestFor($lead())))]);
    }

    /** Bulk "Buat resi" for leads in Dikemas & dikirim: each is checked; the ones that pass are queued. */
    public static function bulkCreate(): BulkAction
    {
        return BulkAction::make('bulkCreateShipments')->label('Buat resi')->icon(Heroicon::OutlinedTruck)
            ->visible(fn () => self::available())
            ->requiresConfirmation()->modalHeading('Buat resi untuk lead terpilih?')
            ->modalDescription('Hanya lead di tahap Dikemas & dikirim tanpa kiriman aktif. Kurir: pilihan pembeli, atau kurir bawaan. Lead yang datanya belum lengkap, cara bayarnya belum dipastikan, atau transfernya belum dikonfirmasi dilewati dan disebutkan.')
            ->action(function (Collection $records) {
                $stage = (new RetailStages)->stage(RetailStages::SHIPPING);
                $booked = 0;
                $skipped = [];

                foreach ($records as $lead) {
                    if ($stage === null || (int) $lead->stage_id !== (int) $stage->getKey() || Shipment::latestFor($lead) !== null) {
                        $skipped[] = $lead->name.' (bukan tahap Dikemas & dikirim atau sudah ada kiriman)';

                        continue;
                    }

                    // Never assumed in bulk: an unknown way of paying, or a transfer nobody has seen, is left for the agent.
                    if (Shipments::payment($lead) === null) {
                        $skipped[] = $lead->name.': cara bayar belum dipastikan (COD atau transfer)';

                        continue;
                    }

                    if (Shipments::unpaid($lead, Shipments::payment($lead))) {
                        $skipped[] = $lead->name.': pembayaran transfer belum dikonfirmasi';

                        continue;
                    }

                    $draft = app(Shipments::class)->draft($lead);

                    if (! $draft->ok()) {
                        $skipped[] = $lead->name.': '.($draft->problems[0] ?? 'data belum lengkap');

                        continue;
                    }

                    try {
                        app(Shipments::class)->book($lead, $draft, auth()->user());
                        $booked++;
                    } catch (CarrierException $e) {
                        $skipped[] = $lead->name.': '.$e->getMessage();
                    }
                }

                Notification::make()->title("{$booked} kiriman dibuat")->body($skipped !== [] ? 'Dilewati: '.implode('; ', array_slice($skipped, 0, 10)) : null)
                    ->{$skipped === [] ? 'success' : 'warning'}()->persistent()->send();
            })->deselectRecordsAfterCompletion();
    }

    /** Bulk "Cetak label": the labels of the selected leads merged into one PDF (App\Shipping\Shipments\LabelSheet), opened in a new tab. */
    public static function bulkLabels(): BulkAction
    {
        return BulkAction::make('bulkShipmentLabels')->label('Cetak label')->icon(Heroicon::OutlinedPrinter)
            ->visible(fn () => self::available())
            ->action(function (Collection $records, $livewire) {
                $sheet = app(LabelSheet::class)->make($records);
                $merged = count($sheet['merged']);
                $skipped = count($sheet['skipped']);
                $lines = array_map(fn ($s) => '• '.e($s['name']).': '.e($s['reason']), array_slice($sheet['skipped'], 0, 20));

                if ($skipped > 20) {
                    $lines[] = '… dan '.($skipped - 20).' lainnya';
                }

                if ($sheet['url'] !== null) {
                    array_unshift($lines, '<a href="'.e($sheet['url']).'" target="_blank" style="text-decoration:underline">Buka PDF label</a> (berlaku 1 jam)');
                    $livewire->js('window.open('.json_encode($sheet['url']).', "_blank")');
                }

                Notification::make()->title("{$merged} label digabung · {$skipped} dilewati")
                    ->body($lines === [] ? null : new HtmlString(implode('<br>', $lines)))
                    ->{$merged === 0 ? 'warning' : ($skipped === 0 ? 'success' : 'warning')}()->persistent()->send();
            });
    }
}
