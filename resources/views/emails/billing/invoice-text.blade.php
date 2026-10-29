Hi {{ $clientName }},

@if (! empty($customMessage))
{{ $customMessage }}

@endif
Please find invoice {{ $number }} attached.

Total: {{ $total }}
@if ($dueDate)
Due: {{ $dueDate }}
@endif

Thank you,
{{ $senderName }}
