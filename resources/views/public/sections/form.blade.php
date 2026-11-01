@php
    $lf = $forms->get((int) ($p['form_id'] ?? 0)) ?? $primaryForm;
    $fv = \App\LeadForms\FormView::block($lf, $tenant, $tracking, $page->getKey(), 'daftar-form', $p['button'] ?? null, $btnCtx['vars']['halaman'] ?? null, $btnCtx['vars']);
@endphp
<section class="{{ $cls }}" id="daftar"><div class="wrap">
    <div class="formbox">
        <h2>{{ $p['heading'] ?? $lf->design('title', 'Daftar') }}</h2>
        @if (filled($p['intro'] ?? $lf->design('description')))<p class="intro">{{ $p['intro'] ?? $lf->design('description') }}</p>@endif
        @include('public.partials.built-form', $fv)
    </div>
</div></section>
