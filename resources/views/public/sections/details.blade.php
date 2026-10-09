<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <table class="det"><tbody>
        @foreach ((array) ($p['rows'] ?? []) as $row)<tr><th>{{ $row['label'] ?? '' }}</th><td>{{ $row['value'] ?? '' }}</td></tr>@endforeach
    </tbody></table>
</div></section>
