Hi {{ $clientName }},

Please find invoice {{ $number }} attached.

Total: {{ $total }}
@if ($dueDate)
Due: {{ $dueDate }}
@endif

Thank you,
{{ $senderName }}
