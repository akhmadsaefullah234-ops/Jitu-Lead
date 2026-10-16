<x-mail::message>
# {{ $headline }}

@foreach ($details as $label => $value)
- **{{ $label }}:** {{ $value }}
@endforeach

<x-mail::button :url="$url">
{{ $linkLabel }}
</x-mail::button>
</x-mail::message>
