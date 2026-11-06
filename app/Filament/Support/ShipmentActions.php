<?php

namespace App\Filament\Support;

use App\Automation\RetailStages;
use App\Leads\ChatShippingDetails;
use App\Models\Lead;
use App\Models\Shipment;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\AutoShipments;
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
        'addr_name' => 'name', 'addr_text' => 'shipping_address', 'addr_rt_rw' => 'rt_rw', 'addr_landmark' => 'landmark',
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
                        $field('addr_kode_pos', 'Kode pos')->maxLength(5),
                    ]),
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
                    default => 'Kiriman sedang dibuat. Resi muncul di lead begitu terbit.',
                })->{$shipment->state === Shipment::FAILED ? 'danger' : 'success'}()->send();
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
