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
      data-form="{{ $form->slug }}" data-page="{{ $pageId }}" @if ($submitPx) data-px-submit="{{ $submitPx }}" @endif>
    @foreach ($fields as $f)
        @php
            $name = 'f['.$f['key'].']';
            $id = $formId.'-'.$f['key'];
            $mark = $req($f) ? ' *' : '';
        @endphp
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
                <fieldset class="lf-g lf-addr"><legend>{{ $f['label'] }}{{ $mark }}</legend>
                    @foreach ($f['parts'] as $part)
                        @php $partReq = $req($f) && in_array($part, \App\LeadForms\FormFields::ADDRESS_REQUIRED, true); @endphp
                        <label class="lf-f {{ in_array($part, ['street', 'landmark'], true) ? 'wide' : '' }}"><span>{{ \App\LeadForms\FormFields::ADDRESS_PARTS[$part] }}{{ $partReq ? ' *' : '' }}</span>
                            @if ($part === 'provinsi')
                                <select name="{{ $name }}[provinsi]" @if ($partReq) required @endif><option value="">Pilih provinsi</option>@foreach (\App\Shipping\Regions::options() as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach</select>
                            @else
                                <input type="text" name="{{ $name }}[{{ $part }}]" @if ($partReq) required @endif
                                    @if ($part === 'kode_pos') inputmode="numeric" maxlength="5" pattern="\d{5}" autocomplete="postal-code" @elseif ($part === 'street') maxlength="250" autocomplete="street-address" @else maxlength="100" @endif
                                    @if ($part === 'rt_rw') placeholder="001/002" @endif>
                            @endif
                        </label>
                    @endforeach
                </fieldset>
        @elseif ($f['type'] === 'photo')
                <label class="lf-f" for="{{ $id }}"><span>{{ $f['label'] }}{{ $mark }}</span>
                    <input id="{{ $id }}" type="file" name="{{ $name }}" accept="image/jpeg,image/png,image/webp" @if ($req($f)) required @endif>
                    <small class="lf-note">JPG, PNG, atau WEBP. Maksimal 5 MB.</small></label>
        @else
                @php
                    [$type, $extra] = match ($f['type']) {
                        'phone' => ['tel', 'inputmode=numeric autocomplete=tel maxlength=20 data-phone=1'],
                        'email' => ['email', 'autocomplete=email maxlength=160'],
                        'number' => ['number', 'inputmode=numeric min=0 step=1'],
                        'date' => ['date', ''],
                        default => ['text', 'maxlength=250'.(($f['maps_to'] ?? '') === 'name' ? ' autocomplete=name' : '')],
                    };
                @endphp
                <label class="lf-f" for="{{ $id }}"><span>{{ $f['label'] }}{{ $mark }}</span>
                    <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" placeholder="{{ $f['placeholder'] ?? '' }}" {{ $extra }} @if ($req($f)) required @endif></label>
        @endif
    @endforeach
    <input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="lf-trap">
    <p class="lf-err" role="alert" hidden></p>
    <button type="submit" class="jb jb-{{ $color }} jb-{{ $size }} lf-btn">@if ($icon)<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>@endif<span>{{ $submit['label'] }}</span></button>
    @if (filled($privacy))<p class="lf-priv">{{ $privacy }}</p>@endif
    @if ($buttons !== [])
        <div class="jbs full lf-more">@foreach ($buttons as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $ctx, 'tracking' => $tracking])@endforeach</div>
    @endif
    <div class="lf-ok" role="status" hidden><strong>Terkirim</strong><p class="lf-ok-msg"></p><a class="jb jb-wa jb-md lf-go" hidden target="_top" rel="noopener"></a></div>
</form>
