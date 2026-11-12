{{--
    A form built under "Formulir" (App\LeadForms\FormView::block). Works without script too: it posts to its own
    address and the server answers with a thank-you page or a redirect.
--}}
@php
    $req = fn ($f) => ! empty($f['required']);
    $color = array_key_exists($submit['color'] ?? '', \App\LandingPages\Buttons::COLORS) ? $submit['color'] : 'brand';
    $size = array_key_exists($submit['size'] ?? '', \App\LandingPages\Buttons::SIZES) ? $submit['size'] : 'lg';
    $icon = \App\LandingPages\Buttons::iconPath($submit['icon'] ?? null);
@endphp
<form id="{{ $formId }}" class="lf" novalidate method="post" action="{{ $endpoint }}" enctype="multipart/form-data"
      data-form="{{ $form->slug }}" data-page="{{ $pageId }}" @if ($submitPx) data-px-submit="{{ $submitPx }}" @endif @if ($twoStep) data-steps="2" @endif
      @if ($quoteUrl) data-quote="{{ $quoteUrl }}" @endif>
    @php
        $split = $twoStep ? \App\LeadForms\FormFields::stepSplit($fields) : null;
        $rtAsked = [];
    @endphp
    @if ($abVariant)<input type="hidden" name="ab" value="{{ $abVariant }}">@endif
    @if ($split !== null)
        <p class="lf-prog" aria-live="polite"><b data-step-now>Langkah 1/2</b><span class="lf-bar"><i></i></span></p>
        <div class="lf-step" data-step="1">
    @endif
    @foreach ($fields as $fi => $f)
        @php
            $name = 'f['.$f['key'].']';
            $id = $formId.'-'.$f['key'];
            $mark = $req($f) ? ' *' : '';
        @endphp
        @if ($split !== null && $fi === $split)
            <button type="button" class="jb jb-{{ $color }} jb-{{ $size }} lf-btn lf-next">Lanjut</button>
        </div>
        <div class="lf-step" data-step="2">
            <button type="button" class="lf-back">&larr; Kembali ke langkah 1</button>
        @endif
        @if ($f['type'] === 'hidden')
                <input type="hidden" name="{{ $name }}" data-src="{{ $f['source'] ?? 'utm_source' }}" data-param="{{ ($f['source'] ?? '') === 'query' ? ($f['value'] ?? '') : '' }}" value="{{ ($f['source'] ?? '') === 'static' ? ($f['value'] ?? '') : '' }}">
        @elseif ($f['type'] === 'textarea')
                <label class="lf-f" for="{{ $id }}"><span>{{ $f['label'] }}{{ $mark }}</span>
                    <textarea id="{{ $id }}" name="{{ $name }}" rows="3" maxlength="2000" placeholder="{{ $f['placeholder'] ?? '' }}" @if ($req($f)) required @endif></textarea></label>
        @elseif ($f['type'] === 'select')
                <label class="lf-f" for="{{ $id }}"><span>{{ $f['label'] }}{{ $mark }}</span>
                    <select id="{{ $id }}" name="{{ $name }}" @if ($req($f)) required @endif>
                        <option value="">{{ $f['placeholder'] ?? 'Pilih...' }}</option>
                        @foreach ($f['options'] ?? [] as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
                    </select></label>
        @elseif ($f['type'] === 'radio')
                <fieldset class="lf-g" @if ($req($f)) data-required="1" @endif><legend>{{ $f['label'] }}{{ $mark }}</legend>
                    <div class="lf-opts">@foreach ($f['options'] ?? [] as $i => $o)<label class="lf-o"><input type="radio" name="{{ $name }}" value="{{ $o }}" @if ($req($f) && $i === 0) required @endif><span>{{ $o }}</span></label>@endforeach</div>
                </fieldset>
        @elseif ($f['type'] === 'checkbox')
                @if (($f['options'] ?? []) === [])
                    <label class="lf-o lf-one"><input type="checkbox" name="{{ $name }}" value="1" @if ($req($f)) required @endif><span>{{ $f['label'] }}{{ $mark }}</span></label>
                @else
                    <fieldset class="lf-g" @if ($req($f)) data-required="1" @endif><legend>{{ $f['label'] }}{{ $mark }}</legend>
                        <div class="lf-opts">@foreach ($f['options'] as $o)<label class="lf-o"><input type="checkbox" name="{{ $name }}[]" value="{{ $o }}"><span>{{ $o }}</span></label>@endforeach</div>
                    </fieldset>
                @endif
        @elseif ($f['type'] === 'address')
                @php
                    $region = \App\LeadForms\FormFields::regionMode($f);
                    $must = \App\LeadForms\FormFields::requiredParts($f);
                    $star = fn ($part) => in_array($part, $must, true) ? ' *' : '';
                    $oneOf = [];
                    $ifNoRt = [];
                    foreach (\App\LeadForms\FormFields::addressRules($f) as $rule => [$pair, $message, $withoutRt]) {
                        foreach ($pair as $p) { $oneOf[$p] = $rule; $ifNoRt[$p] = $withoutRt; }
                    }
                    $cityFirst = \App\LeadForms\FormFields::cityFirst($f);
                    $postalHidden = \App\LeadForms\FormFields::postalHidden($f);
                    $rtChoice = \App\LeadForms\FormFields::rtChoice($f);
                    $softRt = \App\LeadForms\FormFields::softRtCheck($f);
                    $first = $region ? (\App\LeadForms\FormFields::regionChain($f)[0] ?? null) : null;
                    $pickerHints = ['provinsi' => ['Cari provinsi', 'Cari provinsi'], 'kota' => $cityFirst ? ['Ketik kota/kabupaten, mis. kab bekasi', 'Ketik kota/kabupaten, mis. kab bekasi'] : ['Pilih provinsi dulu', 'Cari kota/kabupaten'],
                        'kecamatan' => ['Pilih kota/kabupaten dulu', 'Cari kecamatan'], 'kelurahan' => ['Pilih kecamatan dulu', 'Cari kelurahan/desa']];
                @endphp
                @if ($region)<fieldset class="lf-g lf-addr lf-rg" data-regions="{{ route('regions.children') }}">@else<fieldset class="lf-g lf-addr">@endif<legend>{{ $f['label'] }}{{ $mark }}</legend>
                    @foreach (\App\LeadForms\FormFields::addressParts($f) as $part)
                        @php
                            $partLabel = \App\LeadForms\FormFields::partLabel($f, $part);
                            $ph = \App\LeadForms\FormFields::partPlaceholder($f, $part);
                            $rule = isset($oneOf[$part]) ? 'data-oneof='.$oneOf[$part].(! empty($ifNoRt[$part]) ? ' data-oneof-if=no_rt' : '') : '';
                        @endphp
                        @if ($rtChoice && in_array($part, ['rt_rw', 'dusun'], true) && ! isset($rtAsked[$f['key']]))
                            @php
                                $rtAsked[$f['key']] = true;
                            @endphp
                            <div class="lf-f wide lf-rtk" role="radiogroup" aria-label="Alamat saya memakai"><span>Alamat saya memakai</span>
                                <div class="lf-seg">@foreach (\App\LeadForms\FormFields::RT_KINDS as $kind => $kindLabel)<label class="lf-o"><input type="radio" name="{{ $name }}[rt_kind]" value="{{ $kind }}" @if ($kind === 'rt') checked @endif><span>{{ $kindLabel }}</span></label>@endforeach</div>
                            </div>
                        @endif
                        @if ($region && isset($pickerHints[$part]))
                            <label class="lf-f"><span>{{ $partLabel }}{{ $star($part) }}</span>
                                <span class="rg" data-part="{{ $part }}" data-hint="{{ $pickerHints[$part][1] }}" @if ($part === 'provinsi') data-items="{{ json_encode(\App\Regions\RegionDirectory::children(null), JSON_UNESCAPED_UNICODE) }}" @endif
                                      @if ($cityFirst && $part === 'kota') data-load="{{ route('regions.cities') }}" @endif>
                                    <input type="text" class="rg-q" id="{{ $id }}-{{ $part }}" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $id }}-{{ $part }}-l"
                                           autocomplete="off" placeholder="{{ $part === $first ? ($ph ?: $pickerHints[$part][0]) : $pickerHints[$part][0] }}" @if ($part !== $first) disabled @endif @if ($star($part)) required @endif>
                                    <input type="hidden" name="{{ $name }}[{{ $part }}]" value="">
                                    <span class="rg-l" id="{{ $id }}-{{ $part }}-l" role="listbox" hidden></span>
                                </span></label>
                        @elseif ($part === 'kode_pos' && $postalHidden)
                            {{-- Filled in from the kelurahan; shown only when it has no postal code or was not picked from the list. --}}
                            <label class="lf-f" data-postal-fallback hidden><span>{{ $partLabel }} *</span>
                                <input type="text" name="{{ $name }}[kode_pos]" placeholder="{{ $ph === '12345' ? '5 angka, mis. 17540' : $ph }}" inputmode="numeric" maxlength="5" pattern="\d{5}" autocomplete="postal-code" data-postal>
                                <small class="lf-note">Kode pos kelurahan ini belum ada di data kami. Isi kode pos Anda.</small></label>
                        @elseif ($part === 'provinsi')
                            <label class="lf-f"><span>{{ $partLabel }}{{ $star($part) }}</span>
                                <select name="{{ $name }}[provinsi]" @if ($star($part)) required @endif><option value="">{{ $ph ?: 'Pilih provinsi' }}</option>@foreach (\App\Shipping\Regions::options() as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach</select></label>
                        @else
                            <label class="lf-f {{ in_array($part, ['street', 'landmark'], true) ? 'wide' : '' }}" @if ($rtChoice && in_array($part, ['rt_rw', 'dusun'], true)) data-rtk="{{ $part === 'rt_rw' ? 'rt' : 'dusun' }}" @if ($part === 'dusun') hidden @endif @endif>
                                <span>{{ $partLabel }}{{ $star($part) }}@if (! empty($ifNoRt[$part]) && ! $star($part))<b class="lf-if" data-if-star> *</b>@endif</span>
                                <input type="text" name="{{ $name }}[{{ $part }}]" placeholder="{{ $part === 'kode_pos' && $region ? ($ph === '12345' ? 'Terisi otomatis' : $ph) : $ph }}" {{ $rule }} @if ($star($part)) required @endif
                                    @if ($part === 'kode_pos') inputmode="numeric" maxlength="5" pattern="\d{5}" autocomplete="postal-code" @if ($region) data-postal @endif
                                    @elseif ($part === 'street') maxlength="250" autocomplete="address-line1" @if ($softRt) data-soft-rt @endif
                                    @elseif ($part === 'house') maxlength="100" autocomplete="address-line2"
                                    @else maxlength="100" @endif>
                                @if ($part === 'street' && $softRt)<small class="lf-note">{{ \App\LeadForms\FormFields::SOFT_RT_HINT }}</small><small class="lf-warn" data-soft-rt-warn hidden>{{ \App\LeadForms\FormFields::SOFT_RT_MESSAGE }} (mis. RT 01/RW 02 atau Dusun Krajan).</small>@endif
                            </label>
                        @endif
                    @endforeach
                    @foreach (\App\LeadForms\FormFields::addressRules($f) as $rule => [$pair, $message, $withoutRt])
                        @if (count($pair) > 1 || $withoutRt)<small class="lf-note wide" data-oneof-note="{{ $rule }}" @if ($withoutRt) data-if-note @endif>{{ $message }}</small>@endif
                    @endforeach
                </fieldset>
        @elseif ($f['type'] === 'photo')
                <label class="lf-f" for="{{ $id }}"><span>{{ $f['label'] }}{{ $mark }}</span>
                    <input id="{{ $id }}" type="file" name="{{ $name }}" accept="image/jpeg,image/png,image/webp" @if ($req($f)) required @endif>
                    <small class="lf-note">JPG, PNG, atau WEBP. Maksimal 5 MB.</small></label>
        @else
                @php
                    [$type, $extra] = match ($f['type']) {
                        'phone' => ['tel', 'inputmode=tel autocomplete=tel maxlength=20 data-phone=1'],
                        'email' => ['email', 'autocomplete=email maxlength=160'],
                        'number' => ['number', 'inputmode=numeric min=0 step=1'],
                        'date' => ['date', ''],
                        default => ['text', 'maxlength=250'.(($f['maps_to'] ?? '') === 'name' ? ' autocomplete=name' : '')],
                    };
                @endphp
                @if ($f['type'] === 'number' && ($f['maps_to'] ?? null) === 'quantity')
                    {{-- Quantity: starts at 1, with - and + for thumbs; still typed freely (a whole number of at least 1). --}}
                    <label class="lf-f" for="{{ $id }}"><span>{{ $f['label'] }}{{ $mark }}</span>
                        <span class="lf-qty"><button type="button" class="lf-qb" data-qty="-1" aria-label="Kurangi jumlah">&minus;</button>
                            <input id="{{ $id }}" type="number" name="{{ $name }}" value="1" inputmode="numeric" pattern="[0-9]*" min="1" max="100000" step="1" data-qty-input @if ($req($f)) required @endif>
                            <button type="button" class="lf-qb" data-qty="1" aria-label="Tambah jumlah">+</button></span></label>
                @else
                <label class="lf-f" for="{{ $id }}"><span>{{ $f['label'] }}{{ $mark }}</span>
                    <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" placeholder="{{ $f['placeholder'] ?? '' }}" {{ $extra }} @if ($req($f)) required @endif></label>
                @endif
        @endif
    @endforeach
    @if ($split !== null)
        </div>
    @endif
    @if ($quoteUrl)
        {{-- Price, shipping (with the product's promo) and total for the address picked so far: App\LeadForms\FormQuote. --}}
        {{-- Off under "Tampilkan rincian harga dan ongkir" (LeadForm::showsSummary): then no box and no quoteUrl. --}}
        @php($sp = $sumPreview ?? null)
        <div class="lf-sum" data-sum{{ $sp ? '' : ' hidden' }} aria-live="polite">
            <p><span data-sum-item>{{ $sp ? 'Harga '.$sp['jumlah'].' x '.$sp['harga_teks'] : 'Harga' }}</span><b data-sum-subtotal>{{ $sp['subtotal_teks'] ?? '' }}</b></p>
            <p><span>Ongkir <small data-sum-courier></small></span><b><s data-sum-normal hidden></s> <span data-sum-ship>-</span></b></p>
            <p class="lf-sum-promo" data-sum-promo hidden></p>
            <p class="lf-sum-total"><span>Total</span><b data-sum-total>{{ $sp ? $sp['subtotal_teks'].' + ongkir' : '-' }}</b></p>
            <small class="lf-note" data-sum-note{{ $sp ? '' : ' hidden' }}>{{ $sp ? 'Ongkir dihitung setelah kota/kabupaten dipilih.' : '' }}</small>
        </div>
    @endif
    {{-- Robot trap (App\LeadForms\SpamGuard): a meaningless name per page view, hidden with display:none so autofill skips it. --}}
    @php($guard = \App\LeadForms\SpamGuard::fields())
    <div class="lf-trap" style="display:none" aria-hidden="true"><input type="text" name="{{ $guard['trap'] }}" value="" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore data-form-type="other"></div>
    <input type="hidden" name="{{ \App\LeadForms\SpamGuard::STAMP }}" value="{{ $guard['stamp'] }}">
    <p class="lf-err" role="alert" hidden></p>
    <button type="submit" class="jb jb-{{ $color }} jb-{{ $size }} lf-btn lf-send" @if ($split !== null) data-step-only="2" @endif>@if ($icon)<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>@endif<span>{{ $submit['label'] }}</span></button>
    @if (filled($privacy))<p class="lf-priv">{{ $privacy }}</p>@endif
    @if ($buttons !== [])
        <div class="jbs full lf-more">@foreach ($buttons as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $ctx, 'tracking' => $tracking])@endforeach</div>
    @endif
    <div class="lf-ok" role="status" hidden><strong>Terkirim</strong><p class="lf-ok-msg"></p>
        <p class="lf-ok-wa" hidden>Anda dialihkan ke WhatsApp&hellip; Bila tidak terbuka, ketuk tombol di bawah.</p>
        <a class="jb jb-wa jb-md lf-go" hidden target="_top" rel="noopener"></a></div>
</form>
