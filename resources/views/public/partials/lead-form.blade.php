{{--
    The visitor-facing lead form, used by the hosted form page and by the form
    block of landing pages. Needs: $settings, $endpoint, $tracking, $pageId, $formId.
--}}
@php($formId = $formId ?? 'lead-form')
<form id="{{ $formId }}" class="lf" novalidate data-endpoint="{{ $endpoint }}" data-page="{{ $pageId }}"
      data-google-label="{{ str_starts_with((string) $tracking?->google_tag_id, 'AW-') && $tracking?->google_ads_label ? $tracking->google_tag_id.'/'.$tracking->google_ads_label : '' }}">
    @if (! $endpoint)
        <p class="lf-note">Formulir belum diaktifkan oleh pemilik halaman.</p>
    @else
        <label class="lf-f"><span>Nama lengkap</span><input name="name" type="text" required maxlength="120" autocomplete="name" placeholder="Nama Anda"></label>
        <label class="lf-f"><span>Nomor WhatsApp</span><input name="phone" type="tel" required maxlength="30" autocomplete="tel" inputmode="tel" placeholder="0812xxxxxxxx"></label>
        @if ($settings['ask_email'])
            <label class="lf-f"><span>Email (opsional)</span><input name="email" type="email" maxlength="160" autocomplete="email" placeholder="nama@email.com"></label>
        @endif
        @if ($settings['ask_note'])
            <label class="lf-f"><span>Pesan (opsional)</span><textarea name="note" rows="3" maxlength="1000" placeholder="Tipe unit, budget, atau pertanyaan Anda"></textarea></label>
        @endif
        <input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="lf-trap">
        <p class="lf-err" role="alert" hidden></p>
        <button type="submit" class="lf-btn">{{ $settings['button'] }}</button>
        @if ($settings['show_privacy'] && filled($settings['privacy']))<p class="lf-priv">{{ $settings['privacy'] }}</p>@endif
    @endif
    <div class="lf-ok" role="status" hidden><strong>Terkirim</strong><p>{{ $settings['success'] }}</p></div>
</form>
