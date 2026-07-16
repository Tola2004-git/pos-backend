<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// A pure "something changed, go refetch" signal - deliberately carries no
// order data itself, so the public channel can't leak anything a client
// isn't already authorized to see via the regular REST endpoints (which
// still enforce the cashier/admin scoping). Keeps broadcasting simple: no
// private-channel auth wiring needed against the app's JWT guard.
//
// Broadcasts synchronously (ShouldBroadcastNow, not ShouldBroadcast) so it
// doesn't depend on a queue worker staying alive - this app's QUEUE_CONNECTION
// is "database", and a queued broadcast would just sit unsent until
// something runs `queue:work`.
class OrderChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $orderId, public string $action)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('pos-updates')];
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'action' => $this->action];
    }
}
