<x-mail::message>
# Security Alert

**{{ $failedLoginCount }} failed login attempts** were recorded in the last {{ $windowHours }} hour(s).

This could be someone guessing passwords on one or more accounts. If this doesn't match your own activity, consider reviewing the audit log for the accounts being targeted.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
