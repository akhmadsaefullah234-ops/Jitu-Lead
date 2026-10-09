@php($livewire = $getLivewire())
@if (method_exists($livewire, 'previewHtml'))
    <div x-data="{ w: '100%' }" x-on:change.document.debounce.1200ms="$wire.$refresh()" style="position: sticky; top: 5rem; display: flex; flex-direction: column; gap: .6rem;">
        <div style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; justify-content: space-between;">
            <div style="display: flex; gap: .35rem;">
                <button type="button" x-on:click="w = '390px'" x-bind:style="w === '390px' ? 'font-weight:700' : ''" class="fi-btn fi-size-sm fi-color-gray fi-btn-color-gray" style="padding: .25rem .7rem;">HP</button>
                <button type="button" x-on:click="w = '768px'" x-bind:style="w === '768px' ? 'font-weight:700' : ''" class="fi-btn fi-size-sm fi-color-gray fi-btn-color-gray" style="padding: .25rem .7rem;">Tablet</button>
                <button type="button" x-on:click="w = '100%'" x-bind:style="w === '100%' ? 'font-weight:700' : ''" class="fi-btn fi-size-sm fi-color-gray fi-btn-color-gray" style="padding: .25rem .7rem;">Desktop</button>
            </div>
            <button type="button" x-on:click="$wire.$refresh()" class="fi-btn fi-size-sm fi-color-gray fi-btn-color-gray" style="padding: .25rem .7rem;">Perbarui pratinjau</button>
        </div>
        <p style="margin: 0; font-size: .75rem; color: var(--gray-500);">Pratinjau memuat perubahan yang belum disimpan. Foto yang baru diunggah tampil setelah Simpan.</p>
        <div x-bind:style="`width: ${w}; max-width: 100%; margin: 0 auto; transition: width .2s;`">
            <iframe title="Pratinjau halaman" sandbox srcdoc="{{ $livewire->previewHtml() }}" style="width: 100%; height: 75vh; border: 1px solid var(--gray-300); border-radius: .75rem; background: #fff;"></iframe>
        </div>
    </div>
@endif
