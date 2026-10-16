<x-mail::message>
{!! $body !!}

@if ($button !== '')
<x-mail::button :url="$url">
{{ $button }}
</x-mail::button>
@endif

<x-slot:subcopy>
Anda menerima email ini sebagai admin agensi yang sedang mencoba JITU LEAD. [Berhenti menerima email ini]({{ $unsubscribeUrl }})
</x-slot:subcopy>
</x-mail::message>
