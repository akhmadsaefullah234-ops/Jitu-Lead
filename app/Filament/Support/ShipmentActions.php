<?php

namespace App\Filament\Support;

use App\Automation\RetailStages;
use App\Leads\AddressCheck;
use App\Leads\ChatShippingDetails;
use App\Models\Lead;
use App\Models\Shipment;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\AutoShipments;
use App\Shipping\Shipments\LabelSheet;
use App\Shipping\Shipments\ProviderLocations;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/**
 * "Buat resi", "Cetak label", "Batalkan kiriman" and "Lacak paket", the same in the Inbox ⋯ menu and on the
 * lead page, plus the bulk actions on the lead list (stage Dikemas & dikirim). All hidden while the agency has no
 * provider configured (Pengaturan > Pengiriman). They only talk to App\Shipping\Shipments.
 */
class ShipmentActions
{
    /** Form fields the reasons above the button look at. */
    private const STATE = ['payment', 'transfer_checked', 'addr_kode_pos', 'weight_kg', 'length', 'width', 'height'];

    /** @var array<string, ShipmentDraft> one preview per lead and choice for one request */
    private static array $drafts = [];

    public static function available(): bool
    {
        return Carriers::configured(app(CurrentTenant::class)->get());
    }

    /** Address fields of the "Buat resi" form => the lead's address part they edit (null: the address text, the name). */
    private const ADDRESS_FIELDS = [
        'addr_name' => 'name', 'addr_text' => 'shipping_address', 'addr_rt_rw' => 'rt_rw', 'addr_house' => 'house', 'addr_landmark' => 'landmark',
        'addr_kelurahan' => 'kelurahan', 'addr_kecamatan' => 'kecamatan', 'addr_kota' => 'kota', 'addr_kode_pos' => 'kode_pos',
    ];

    /**
     * @param  array<string, mixed>  $address  the address fields of the form, when shown
     * @param  array<string, mixed>  $edit  the package details the agent changed (see self::edits())
     */
    private static function draft(Lead $lead, ?string $location, ?string $rate, array $address = [], ?string $payment = null, array $edit = []): ShipmentDraft
    {
        $lead = self::withItem(self::withAddress($lead, $address), $edit);
        $key = $lead->getKey().'|'.$location.'|'.$rate.'|'.$payment.'|'.md5(json_encode([$lead->name, $lead->shipping_address, $lead->shipping_address_parts, $lead->order_items, $lead->quantity, $lead->variant, $edit]));

        return self::$drafts[$key] ??= app(Shipments::class)->draft($lead, ['location_id' => $location, 'rate_code' => $rate, 'payment' => $payment, 'edit' => $edit]);
    }

    /** The order has one product: its quantity and variant can be changed in "Buat resi". */
    private static function singleItem(Lead $lead): bool
    {
        return count(app(Shipments::class)->items($lead)) === 1;
    }

    /** The lead with the quantity and variant changed in the form (not saved). */
    private static function withItem(Lead $lead, array $edit): Lead
    {
        $quantity = isset($edit['quantity']) ? max(1, (int) $edit['quantity']) : null;
        $variant = isset($edit['variant']) ? trim((string) $edit['variant']) : null;

        if (($quantity === null && $variant === null) || ! self::singleItem($lead)) {
            return $lead;
        }

        $copy = clone $lead;
        $items = (array) $lead->order_items;

        if ($items !== []) {
            $key = array_key_first($items);
            $items[$key] = array_merge($items[$key], array_filter(['quantity' => $quantity, 'variant' => $variant], fn ($v) => $v !== null));
            $copy->order_items = $items;
        }

        $copy->quantity = $quantity ?? $lead->quantity;
        $copy->variant = $variant ?? $lead->variant;

        return $copy;
    }

    /** The current quantity and variant of a one-product order, for the form's placeholders. */
    private static function item(Lead $lead): array
    {
        $first = collect((array) $lead->order_items)->first();

        return ['quantity' => (int) ($first['quantity'] ?? $first['jumlah'] ?? $lead->quantity ?? 1), 'variant' => $first['variant'] ?? $lead->variant];
    }

    /** @return array<string, mixed> the package details filled in the form (empty: as calculated) */
    private static function edits(Get|Closure $get): array
    {
        $money = fn ($v) => filled($v) ? max(0, (int) preg_replace('/\D/', '', (string) $v)) : null;
        $number = fn ($v) => filled($v) && is_numeric(str_replace(',', '.', (string) $v)) ? (float) str_replace(',', '.', (string) $v) : null;
        $kg = $number($get('weight_kg'));

        return array_filter([
            'charged' => $money($get('charged')),
            'cod_amount' => $money($get('cod_amount')),
            'description' => filled($get('description')) ? trim((string) $get('description')) : null,
            'item_value' => $money($get('item_value')),
            'quantity' => filled($get('quantity')) ? max(1, (int) $get('quantity')) : null,
            'variant' => filled($get('variant')) ? trim((string) $get('variant')) : null,
            'weight_grams' => $kg !== null ? (int) round($kg * 1000) : null,
            'length' => ($l = $number($get('length'))) ? (int) ceil($l) : null,
            'width' => ($w = $number($get('width'))) ? (int) ceil($w) : null,
            'height' => ($h = $number($get('height'))) ? (int) ceil($h) : null,
            'note' => filled($get('note')) ? trim((string) $get('note')) : null,
            'insurance' => $get('insurance'),
        ], fn ($v) => $v !== null);
    }

    /**
     * Why "Buat resi" cannot book yet, said right above the button (and in a notification when it is pressed anyway).
     *
     * @return list<string>
     */
    private static function reasons(Lead $lead, array $data, ?ShipmentDraft $draft): array
    {
        $reasons = [];
        $chosen = Shipments::payment($lead, self::asksPayment($lead) ? ($data['payment'] ?? null) : null);

        if ($chosen === null) {
            $reasons[] = 'Pilih cara bayar dulu (COD atau Transfer).';
        } elseif (Shipments::unpaid($lead, $chosen) && ! ($data['transfer_checked'] ?? false)) {
            $reasons[] = 'Centang "Saya sudah memastikan transfer pembeli masuk" dulu.';
        }

        if (self::needsAddress($lead) && filled($data['addr_kode_pos'] ?? null) && ! preg_match('/^\d{5}$/', trim((string) $data['addr_kode_pos']))) {
            $reasons[] = 'Kode pos harus 5 angka.';
        }

        if (filled($data['weight_kg'] ?? null) && ! is_numeric(str_replace(',', '.', (string) $data['weight_kg']))) {
            $reasons[] = 'Berat harus angka (kg).';
        }

        $dims = array_filter([$data['length'] ?? null, $data['width'] ?? null, $data['height'] ?? null], 'filled');

        if ($dims !== [] && count($dims) < 3) {
            $reasons[] = 'Isi panjang, lebar dan tinggi sekaligus, atau kosongkan ketiganya.';
        }

        foreach ($draft?->problems ?? [] as $problem) {
            if ($chosen === null && str_starts_with($problem, 'Cara bayar belum dipastikan')) {
                continue;
            }

            $reasons[] = $problem;
        }

        return array_values(array_unique($reasons));
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
            'Nama penerima belum diisi.', 'Alamat kirim lengkap belum diisi.', 'Kecamatan atau kode pos tujuan belum diisi di alamat lead.', AddressCheck::MESSAGE,
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
        $current = fn (Get $get) => self::draft($lead(), $get('location_id'), $get('rate_code'), $address($get), $payment($get), self::edits($get));
        $field = fn (string $name, string $label) => TextInput::make($name)->label($label)->live(onBlur: true)->maxLength(150)->default(fn () => self::addressState($lead())[$name] ?? null);
        $rp = fn (?int $v) => 'Rp'.number_format((int) $v, 0, ',', '.');
        $detail = fn (string $name, string $label) => TextInput::make($name)->label($label)->live(onBlur: true);

        $self = Action::make($name)->label('Buat resi')->icon(Heroicon::OutlinedTruck)->color('gray')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && ! $l->trashed() && Shipment::latestFor($l) === null)
            ->modalHeading('Buat resi otomatis')
            ->modalDescription('Periksa dulu sebelum dikirim ke penyedia pengiriman. Resi yang terbit dikirim ke pembeli lewat WhatsApp seperti biasa.')
            ->modalSubmitActionLabel('Buat resi');

        // Always the modal's own (primary) button; disabled with the reason written right above it.
        $self->modalSubmitAction(fn (Action $action) => $action->disabled(fn () => self::reasons($lead(), $self->getRawData(), self::formDraft($lead(), $self->getRawData())) !== []));

        return $self
            ->schema([
                Radio::make('payment')->label('Cara bayar')->options(Shipments::PAYMENTS)->inline()->live()->default(null)
                    ->visible(fn () => self::asksPayment($lead()))
                    ->helperText(fn () => ($s = app(Shipments::class)->paymentSuggestion($lead())) !== null
                        ? 'Saran dari chat: '.(Shipments::PAYMENTS[$s] ?? $s).'. Pastikan dulu, lalu pilih.'
                        : 'Cara bayar lead ini belum diketahui. Pilih sesuai kesepakatan dengan pembeli.'),
                Checkbox::make('transfer_checked')->label('Saya sudah memastikan transfer pembeli masuk')
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
                                    $missing = ChatShippingDetails::missing($copy->shipping_address, (array) $copy->shipping_address_parts);
                                    Notification::make()->title(($found['labels'] ?? []) === [] ? 'Tidak ada data alamat baru di chat.' : 'Dari chat: '.implode(', ', $found['labels']).'. Periksa lalu buat resi.')
                                        ->body($missing !== [] ? 'Alamat belum lengkap: '.implode(' ', $missing) : null)
                                        ->{($found['labels'] ?? []) === [] || $missing !== [] ? 'warning' : 'success'}()->send();
                                }),
                        ])->key('addressActions')->columnSpanFull(),
                        $field('addr_name', 'Nama penerima'),
                        $field('addr_rt_rw', 'RT/RW'),
                        Textarea::make('addr_text')->label('Alamat lengkap')->rows(2)->live(onBlur: true)->maxLength(1000)->columnSpanFull()->default(fn () => self::addressState($lead())['addr_text'] ?? null),
                        $field('addr_house', 'Nomor rumah')->helperText(fn (Get $get) => AddressCheck::hasRtRw($get('addr_rt_rw')) ? null : 'Alamat tanpa RT/RW: isi nomor rumah atau patokan.'),
                        $field('addr_landmark', 'Patokan'),
                        $field('addr_kelurahan', 'Kelurahan / desa'),
                        $field('addr_kecamatan', 'Kecamatan'),
                        $field('addr_kota', 'Kota / kabupaten'),
                        $field('addr_kode_pos', 'Kode pos')->maxLength(5),
                    ]),
                // "Pilih lokasi" lives here (not in the Inbox header): when matching found several places or none.
                Actions::make([
                    LocationActions::choose(fn () => $lead(), 'pickLocation')->link()
                        ->visible(fn () => self::available() && ($l = $lead()) !== null && ProviderLocations::key($l) !== null
                            && in_array($l->provider_location_state, [ProviderLocations::MULTIPLE, ProviderLocations::NONE], true))
                        ->after(function (Set $set) {
                            self::$drafts = [];
                            $set('location_id', null);
                        }),
                ])->key('locationActions'),
                Select::make('location_id')->label('Kelurahan tujuan')->live()
                    ->options(fn (Get $get) => collect(self::draft($lead(), null, null, $address($get), $payment($get))->candidates)->mapWithKeys(fn (CarrierLocation $l) => [$l->id => $l->label()])->all())
                    ->default(fn () => self::draft($lead(), null, null)->destination?->id)
                    ->visible(fn (Get $get) => count(($d = self::draft($lead(), null, null, $address($get), $payment($get)))->candidates) > 1 || ($d->destination === null && $d->candidates !== [])),
                Select::make('rate_code')->label('Kurir dan layanan')->live()
                    ->options(fn (Get $get) => $current($get)->choices())
                    ->default(fn () => self::draft($lead(), null, null)->rate?->key())
                    ->visible(fn (Get $get) => self::draft($lead(), $get('location_id'), null, $address($get), $payment($get))->rates !== []),
                Section::make('Rincian paket')
                    ->description('Kosongkan untuk memakai hitungan (terlihat samar di tiap isian). Ubah bila ada kesepakatan lain di chat; perubahan dicatat di lead.')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        $detail('charged', 'Ongkir ditagih ke pembeli')->prefix('Rp')->numeric()->minValue(0)
                            ->placeholder(fn (Get $get) => number_format((int) $current($get)->charged, 0, ',', '.'))
                            ->helperText(fn (Get $get) => ($d = $current($get))->promoCharged !== null ? 'Hasil promo ongkir: '.$rp($d->promoCharged).' · ongkir asli '.$rp($d->rate?->price) : null)
                            ->hintAction(Action::make('usePromo')->label('Pakai promo')->link()
                                ->visible(fn (Get $get) => ($d = $current($get))->promoCharged !== null && $d->charged !== $d->promoCharged)
                                ->action(fn (Get $get, Set $set) => $set('charged', $current($get)->promoCharged))),
                        $detail('cod_amount', 'Total COD')->prefix('Rp')->numeric()->minValue(0)
                            ->visible(fn (Get $get) => $current($get)->cod)
                            ->placeholder(fn (Get $get) => number_format($current($get)->calculatedCod, 0, ',', '.'))
                            ->helperText('Otomatis harga × jumlah + ongkir ditagih. Isi bila ada kesepakatan khusus.'),
                        $detail('description', 'Isi paket')->maxLength(150)->columnSpanFull()
                            ->placeholder(fn (Get $get) => $current($get)->description),
                        $detail('item_value', 'Nilai barang (untuk asuransi)')->prefix('Rp')->numeric()->minValue(0)
                            ->placeholder(fn (Get $get) => number_format($current($get)->itemValue, 0, ',', '.')),
                        $detail('weight_kg', 'Berat')->suffix('kg')
                            ->placeholder(fn (Get $get) => str_replace('.', ',', (string) round($current($get)->weightGrams / 1000, 2))),
                        $detail('quantity', 'Jumlah')->numeric()->integer()->minValue(1)->maxValue(999)
                            ->visible(fn () => self::singleItem($lead()))
                            ->placeholder(fn () => (string) self::item($lead())['quantity']),
                        $detail('variant', 'Varian')->maxLength(100)
                            ->visible(fn () => self::singleItem($lead()))
                            ->placeholder(fn () => self::item($lead())['variant'] ?: '-'),
                        $detail('length', 'Panjang')->suffix('cm')->numeric()->minValue(1)
                            ->placeholder(fn (Get $get) => (string) ($current($get)->dimensions['length'] ?? '')),
                        $detail('width', 'Lebar')->suffix('cm')->numeric()->minValue(1)
                            ->placeholder(fn (Get $get) => (string) ($current($get)->dimensions['width'] ?? '')),
                        $detail('height', 'Tinggi')->suffix('cm')->numeric()->minValue(1)
                            ->placeholder(fn (Get $get) => (string) ($current($get)->dimensions['height'] ?? '')),
                        Toggle::make('insurance')->label('Asuransi')->live()
                            ->default(fn () => (bool) app(Shipments::class)->account()?->setting('insurance', false)),
                        $detail('note', 'Catatan untuk kurir')->maxLength(200)->columnSpanFull()
                            ->placeholder(fn (Get $get) => $current($get)->note ?: 'mis. rumah pagar hijau, titip ke tetangga'),
                    ]),
                Text::make(fn (Get $get) => ($d = $current($get))->problems === [] ? self::preview($d) : null)->columnSpanFull(),
                Text::make(fn (Get $get) => self::reasonsBox(self::reasons($lead(), array_map(fn ($key) => $get($key), array_combine(self::STATE, self::STATE)), $current($get))))->columnSpanFull()->key('reasons'),
            ])
            ->action(function (array $data, Action $action) use ($lead) {
                $record = $lead();
                $draft = self::formDraft($record, $data, fresh: true);
                $reasons = self::reasons($record, $data, $draft);

                if ($reasons !== []) {
                    self::stopped($record, $reasons, $action);
                }

                self::savePayment($record, self::asksPayment($record) ? ($data['payment'] ?? null) : null);
                self::saveAddress($record, $data);

                try {
                    [$shipment, $new] = app(Shipments::class)->book($record, $draft, auth()->user());
                } catch (CarrierException $e) {
                    self::stopped($record, [$e->getMessage()], $action);
                }

                if ($new) {
                    self::saveDetails($record, $draft, self::edits(fn (string $key) => $data[$key] ?? null));
                }

                $shipment->refresh();
                Notification::make()->title(match (true) {
                    ! $new => 'Kiriman ini sudah dibuat sebelumnya ('.($shipment->awb ?: $shipment->client_order_no).').',
                    $shipment->state === Shipment::SHIPPED => 'Resi '.$shipment->awb.' terbit.',
                    $shipment->state === Shipment::FAILED => 'Kiriman gagal dibuat: '.$shipment->error,
                    $shipment->waitingBalance() => 'Kiriman menunggu saldo: '.$shipment->error,
                    default => 'Kiriman sedang dibuat. Resi muncul di lead begitu terbit.',
                })->{$shipment->state === Shipment::FAILED ? 'danger' : ($shipment->waitingBalance() ? 'warning' : 'success')}()->send();
            });
    }

    /** The draft for the form's state (the submit button, the action): the same one the preview shows. */
    private static function formDraft(?Lead $lead, array $data, bool $fresh = false): ?ShipmentDraft
    {
        if ($lead === null) {
            return null;
        }

        $get = fn (string $key) => $data[$key] ?? null;
        $address = self::needsAddress($lead) ? array_intersect_key($data, self::ADDRESS_FIELDS) : [];
        $payment = self::asksPayment($lead) ? ($data['payment'] ?? null) : null;

        if ($fresh) {
            self::$drafts = [];
        }

        return self::draft($lead, $data['location_id'] ?? null, $data['rate_code'] ?? null, $address, $payment, self::edits($get));
    }

    /** Booking stopped: the agent is always told why, and it is logged (lead id only) so a silent button can be traced. */
    private static function stopped(Lead $lead, array $reasons, Action $action): never
    {
        Notification::make()->title('Resi belum bisa dibuat')
            ->body(new HtmlString(implode('<br>', array_map(fn ($r) => '• '.e($r), $reasons))))->danger()->persistent()->send();
        Log::warning('Buat resi stopped before booking', ['tenant' => app(CurrentTenant::class)->id(), 'lead' => $lead->getKey(), 'reasons' => count($reasons)]);
        $action->halt();
    }

    private static function reasonsBox(array $reasons): ?HtmlString
    {
        return $reasons === [] ? null : new HtmlString('<div style="color:var(--danger-600);padding:.5rem .75rem;border:1px solid var(--danger-300);border-radius:.5rem;background:var(--danger-50)"><strong>Belum bisa membuat resi:</strong>'
            .implode('', array_map(fn ($r) => '<div>• '.e($r).'</div>', $reasons)).'</div>');
    }

    /**
     * What was used goes back onto the lead: the shipping charged, the total, quantity and variant. When the agent
     * changed anything, one activity says what ("Rincian resi diubah agen: Ongkir ditagih Rp45.000 → Rp32.900").
     */
    private static function saveDetails(Lead $lead, ShipmentDraft $draft, array $edit): void
    {
        $changes = [];
        $edited = self::withItem($lead, $edit);

        foreach (['quantity', 'variant', 'order_items'] as $key) {
            if ($edited->{$key} != $lead->{$key}) {
                $changes[$key] = $edited->{$key};
            }
        }

        if ((int) $lead->shipping_cost !== (int) $draft->charged || $lead->shipping_cost === null) {
            $changes['shipping_cost'] = $draft->charged;
        }

        $total = $draft->cod ? $draft->codAmount - (int) $draft->rate?->codFee : $draft->goodsTotal + (int) $draft->charged;

        if ((int) $lead->deal_value !== $total) {
            $changes['deal_value'] = $total;
        }

        $before = ['quantity' => $lead->quantity, 'variant' => $lead->variant];

        if ($changes !== []) {
            $lead->forceFill($changes)->save();
        }

        $items = [];

        foreach (['quantity' => 'Jumlah', 'variant' => 'Varian'] as $key => $label) {
            if (array_key_exists($key, $changes)) {
                $items[] = $label.' '.($before[$key] ?? '-').' → '.$changes[$key];
            }
        }

        $lines = [...array_map(fn ($label, $pair) => $label.' '.$pair[0].' → '.$pair[1], array_keys($draft->edits), $draft->edits), ...$items];

        if ($lines !== []) {
            $lead->activities()->create(['user_id' => auth()->id(), 'type' => 'note', 'body' => 'Rincian resi diubah agen: '.implode('; ', $lines).'.', 'meta' => ['edits' => array_keys($draft->edits)]]);
        }
    }

    public static function preview(ShipmentDraft $draft): HtmlString
    {
        if ($draft->problems !== []) {
            return new HtmlString('<div style="color:var(--danger-600);display:grid;gap:.25rem">'.implode('', array_map(fn ($p) => '<div>• '.e($p).'</div>', $draft->problems)).'</div>');
        }

        $warnings = implode('', array_map(fn ($w) => '<div style="color:var(--warning-700);margin-top:.35rem">⚠ '.e($w).'</div>', $draft->warnings()));

        return new HtmlString('<div style="white-space:pre-line;padding:.6rem .8rem;border-radius:.5rem;background:var(--gray-50);border:1px solid var(--gray-200)">'.e($draft->summary()).'</div>'.$warnings);
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

    /** "Resi otomatis dalam 25 menit · Batalkan": the agent stops a scheduled automatic resi. */
    /** The lead's last parcel, when the provider refused it and nothing else is on its way. */
    public static function failedFor(?Lead $lead): ?Shipment
    {
        if ($lead === null) {
            return null;
        }

        // A parcel held for balance is still the lead's active one, and "Coba lagi" is how it is sent after a top-up.
        if (($active = Shipment::latestFor($lead)) !== null) {
            return $active->waitingBalance() ? $active : null;
        }

        $last = Shipment::query()->where('lead_id', $lead->getKey())->latest('id')->first();

        return $last?->retryable() ? $last : null;
    }

    /** What a retried parcel is sent with: as it was checked or changed in "Buat resi". */
    public static function retryDescription(Shipment $shipment): string
    {
        $rp = fn (?int $v) => 'Rp'.number_format((int) $v, 0, ',', '.');

        return implode(' · ', array_filter([
            $shipment->courier,
            'ongkir ditagih '.$rp($shipment->charged),
            $shipment->is_cod ? 'Total COD '.$rp($shipment->cod_amount) : 'transfer',
        ])).'. Gagal sebelumnya: '.($shipment->error ?: '-').' Pastikan penyebabnya sudah diperbaiki.';
    }

    public static function retryShipment(Shipment $shipment, ?string $orderNo = null): void
    {
        try {
            app(Shipments::class)->retry($shipment, auth()->user(), $orderNo);
        } catch (CarrierException $e) {
            Notification::make()->title('Kiriman belum bisa dicoba lagi')->body($e->getMessage())->danger()->send();

            return;
        }

        $shipment->refresh();
        Notification::make()->title(match (true) {
            $shipment->state === Shipment::SHIPPED => 'Resi '.$shipment->awb.' terbit.',
            $shipment->state === Shipment::FAILED => 'Kiriman masih gagal: '.$shipment->error,
            $shipment->waitingBalance() => 'Kiriman masih menunggu saldo: '.$shipment->error,
            default => 'Kiriman dikirim ulang. Resi muncul di lead begitu terbit.',
        })->{$shipment->state === Shipment::FAILED ? 'danger' : ($shipment->waitingBalance() ? 'warning' : 'success')}()->send();
    }

    /** "Coba lagi": the refused parcel sent again with the details it had (App\Shipping\Shipments\Shipments::retry). */
    public static function retry(Closure $lead, string $name = 'retryShipment'): Action
    {
        return Action::make($name)->label('Coba lagi kiriman')->icon(Heroicon::OutlinedArrowPath)->color('warning')
            ->visible(fn () => self::available() && self::failedFor($lead()) !== null)
            ->requiresConfirmation()
            ->modalHeading('Coba lagi kiriman yang gagal?')
            ->modalDescription(fn () => ($s = self::failedFor($lead())) ? self::retryDescription($s) : null)
            ->modalSubmitActionLabel('Coba lagi')
            ->action(fn () => ($s = self::failedFor($lead())) ? self::retryShipment($s) : null);
    }

    public static function cancelAuto(Closure $lead, string $name = 'cancelAutoShipment'): Action
    {
        return Action::make($name)->label(fn () => 'Resi otomatis dalam '.(AutoShipments::minutesLeft($lead()) ?? 0).' menit · Batalkan')
            ->icon(Heroicon::OutlinedClock)->color('warning')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && $l->auto_shipment_state === AutoShipments::SCHEDULED)
            ->requiresConfirmation()->modalHeading('Batalkan resi otomatis?')
            ->modalDescription('Resi tidak dibuat otomatis untuk lead ini. Buat resi manual dari menu Pengiriman bila sudah dicek.')
            ->modalSubmitActionLabel('Batalkan resi otomatis')
            ->action(function () use ($lead) {
                app(AutoShipments::class)->cancel($lead(), auth()->user());
                Notification::make()->title('Resi otomatis dibatalkan')->success()->send();
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

    /** "Batalkan kiriman" on the lead page and in the Inbox: the lead's newest active parcel. */
    public static function cancel(Closure $lead, string $name = 'cancelShipment'): Action
    {
        return self::cancelShipment(fn () => ($l = $lead()) !== null ? Shipment::latestFor($l) : null, $name);
    }

    /** Most parcels one bulk "Batalkan" or "Hapus" handles; the rest are left for another run. */
    public const BULK_LIMIT = 50;

    /** Shown where a parcel can no longer be cancelled here. */
    public const PICKED_UP_HINT = 'Sudah diambil kurir: hubungi penyedia';

    /**
     * "Batalkan kiriman" for one parcel: the row of the Pengiriman table, or the lead's newest one. The rules are
     * Shipment::cancellable() and Shipments::cancel(), which also writes the lead's activity and the parcel's history.
     *
     * @param  Closure(Action): ?Shipment  $shipment
     */
    public static function cancelShipment(Closure $shipment, string $name = 'cancelShipment'): Action
    {
        return Action::make($name)->label('Batalkan kiriman')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (Action $action) => self::available() && ($shipment($action)?->cancellable() ?? false))
            ->requiresConfirmation()->modalHeading('Batalkan kiriman?')
            ->modalDescription(fn (Action $action) => ($s = $shipment($action)) ? self::cancelDescription($s) : null)
            ->modalSubmitActionLabel('Batalkan kiriman')
            ->schema([
                TextInput::make('reason')->label('Alasan')->default('Dibatalkan toko')->maxLength(200)
                    ->helperText('Opsional. Dikirim ke penyedia dan dicatat di aktivitas lead.'),
            ])
            ->action(function (Action $action, array $data) use ($shipment) {
                $parcel = $shipment($action);

                if ($parcel === null) {
                    return;
                }

                try {
                    app(Shipments::class)->cancel($parcel, auth()->user(), self::reason($data));
                } catch (CarrierException $e) {
                    Notification::make()->title('Kiriman belum dibatalkan')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Kiriman dibatalkan')->success()->send();
            });
    }

    public static function cancelDescription(Shipment $shipment): string
    {
        return 'Kiriman '.self::parcelName($shipment).' untuk '.($shipment->lead?->name ?? 'pembeli').' dibatalkan di '
            .config('carriers.labels.'.$shipment->provider, 'penyedia').'. Hanya bisa sebelum paket diambil kurir. Resi lama tidak berlaku lagi; buat resi baru bila perlu.';
    }

    /** "resi JNE0001234", or "pesanan TRD…" / "pesanan JL-…" before the tracking number. */
    public static function parcelName(Shipment $shipment): string
    {
        return filled($shipment->awb) ? 'resi '.$shipment->awb : 'pesanan '.($shipment->provider_order_no ?: $shipment->client_order_no);
    }

    private static function reason(array $data): string
    {
        return trim((string) ($data['reason'] ?? '')) ?: 'Dibatalkan toko';
    }

    /** "Hapus" (off the lists only) is for admins and team leaders. */
    public static function canDelete(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false;
    }

    /** "Hapus" for one row of the Pengiriman table: Shipment::deletable() and Shipments::delete(). */
    public static function deleteShipment(string $name = 'deleteShipment'): Action
    {
        return Action::make($name)->label('Hapus')->icon(Heroicon::OutlinedTrash)->color('danger')
            ->visible(fn (Shipment $record) => self::available() && self::canDelete() && $record->deletable())
            ->requiresConfirmation()->modalHeading('Hapus kiriman dari daftar?')
            ->modalDescription(fn (Shipment $record) => 'Ini tidak membatalkan pesanan di penyedia. Kiriman '.self::parcelName($record).' untuk '
                .($record->lead?->name ?? 'pembeli').' hilang dari semua tab Pengiriman; lead bisa dibuatkan resi baru.')
            ->modalSubmitActionLabel('Hapus dari daftar')
            ->action(function (Shipment $record) {
                try {
                    app(Shipments::class)->delete($record, auth()->user());
                } catch (CarrierException $e) {
                    Notification::make()->title('Kiriman belum dihapus')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Kiriman dihapus dari daftar')->success()->send();
            });
    }

    /** Bulk "Batalkan terpilih": each parcel on its own; one that cannot be cancelled or fails does not stop the rest. */
    public static function bulkCancel(): BulkAction
    {
        return BulkAction::make('cancelSelected')->label('Batalkan terpilih')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn () => self::available())
            ->requiresConfirmation()->modalHeading('Batalkan kiriman terpilih?')
            ->modalDescription('Tiap kiriman dibatalkan di penyedia, paling banyak '.self::BULK_LIMIT.' sekali jalan. Kiriman yang sudah diambil kurir atau sudah selesai dilewati.')
            ->modalSubmitActionLabel('Batalkan')
            ->schema([TextInput::make('reason')->label('Alasan')->default('Dibatalkan toko')->maxLength(200)])
            ->action(function (Collection $records, array $data) {
                $reason = self::reason($data);

                self::bulk($records, 'dibatalkan', function (Shipment $s) use ($reason) {
                    if (! $s->cancellable()) {
                        return $s->pickedUp() ? 'sudah diambil kurir' : ($s->state === Shipment::CANCELLED ? 'sudah dibatalkan' : 'tidak bisa dibatalkan');
                    }

                    app(Shipments::class)->cancel($s, auth()->user(), $reason);

                    return null;
                });
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Bulk "Hapus terpilih" (admins and team leaders): only parcels no longer live at the provider. */
    public static function bulkDelete(): BulkAction
    {
        return BulkAction::make('deleteSelected')->label('Hapus terpilih')->icon(Heroicon::OutlinedTrash)->color('danger')
            ->visible(fn () => self::available() && self::canDelete())
            ->requiresConfirmation()->modalHeading('Hapus kiriman terpilih dari daftar?')
            ->modalDescription('Ini tidak membatalkan pesanan di penyedia. Hanya kiriman yang dibatalkan, gagal sebelum diterima penyedia, atau resi yang ditautkan manual yang dihapus; paling banyak '.self::BULK_LIMIT.' sekali jalan. Kiriman yang masih aktif dilewati: batalkan dulu.')
            ->modalSubmitActionLabel('Hapus dari daftar')
            ->action(fn (Collection $records) => self::bulk($records, 'dihapus', function (Shipment $s) {
                if (! $s->deletable()) {
                    return 'masih aktif di penyedia, batalkan dulu';
                }

                app(Shipments::class)->delete($s, auth()->user());

                return null;
            }))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Runs $each on every parcel (at most BULK_LIMIT) and sends one summary: "3 dibatalkan, 2 dilewati (sudah diambil
     * kurir)". $each returns why a parcel was skipped, or null when it was done; an error skips only that parcel.
     *
     * @param  Closure(Shipment): ?string  $each
     */
    private static function bulk(Collection $records, string $done, Closure $each): void
    {
        $count = 0;
        $skipped = [];
        $lines = [];

        foreach ($records->values() as $i => $shipment) {
            $name = ($shipment->lead?->name ?? 'Kiriman').' ('.($shipment->awb ?: $shipment->provider_order_no ?: $shipment->client_order_no).')';

            if ($i >= self::BULK_LIMIT) {
                $skipped[] = 'lebih dari '.self::BULK_LIMIT.', jalankan lagi';

                continue;
            }

            try {
                $why = $each($shipment);
            } catch (CarrierException $e) {
                $why = $e->getMessage();
            } catch (\Throwable $e) {
                report($e);
                $why = 'gagal, coba lagi';
            }

            if ($why === null) {
                $count++;
            } else {
                $skipped[] = $why;
                $lines[] = '• '.e($name).': '.e($why);
            }
        }

        $reasons = collect($skipped)->countBy()->map(fn ($n, $why) => count(array_unique($skipped)) > 1 ? $n.' '.$why : $why)->implode(', ');
        $title = $count.' '.$done.($skipped !== [] ? ', '.count($skipped).' dilewati ('.$reasons.')' : '');

        $notification = Notification::make()->title($title)->body($lines === [] ? null : new HtmlString(implode('<br>', array_slice($lines, 0, 20))))
            ->{$count === 0 ? 'warning' : ($skipped === [] ? 'success' : 'warning')}();

        ($skipped === [] ? $notification : $notification->persistent())->send();
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
                $sheet = app(LabelSheet::class)->make($records, auth()->user());
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
