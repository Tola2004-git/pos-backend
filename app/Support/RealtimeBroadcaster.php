<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

// The OrderChanged/TableChanged events broadcast synchronously (ShouldBroadcastNow),
// which means a Reverb outage would otherwise throw mid-request and take down
// the actual order/table operation with it. Broadcasting is a nice-to-have
// live-update signal, not something core POS actions should ever depend on
// to succeed - so every call site goes through here instead of the bare
// broadcast() helper.
class RealtimeBroadcaster
{
    public static function send(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            Log::warning('Realtime broadcast failed: ' . $e->getMessage(), [
                'event' => get_class($event),
            ]);
        }
    }
}
