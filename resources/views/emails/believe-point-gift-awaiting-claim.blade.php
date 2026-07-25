<x-mail::message>
# You have a Believe Points gift waiting

**{{ $senderName }}** sent you **{{ $amountLabel }} BP**.

@if (!empty($occasion))
**Occasion:** {{ $occasion }}
@endif

@if (!empty($message))
> {{ $message }}
@endif

Accept the gift on Believe In Unity to add it to your **Gifted BP** wallet. You can then spend it on gift cards, Marketplace, Learning Hub, and more.

The sender can cancel until you accept. This offer expires in about **{{ $holdDays }} days**.

<x-mail::button :url="$claimUrl">
Accept / Collect gift
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
