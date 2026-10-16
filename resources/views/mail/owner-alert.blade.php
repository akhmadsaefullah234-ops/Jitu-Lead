{{ $headline }}

@foreach ($details as $label => $value)
{{ $label }}: {{ $value }}
@endforeach

{{ $linkLabel }}: {{ $url }}
