<x-mail::message>
# Chat support baru

**{{ $agency }}** ({{ $user }}) menulis di chat support:

@foreach (preg_split('/\R/', (string) $body) as $line)
> {{ $line }}
@endforeach

<x-mail::button :url="$url">
Balas di admin
</x-mail::button>
</x-mail::message>
