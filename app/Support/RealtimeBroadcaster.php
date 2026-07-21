<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

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
