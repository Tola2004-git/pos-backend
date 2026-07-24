<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ShiftChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $shiftId, public string $action)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('pos-updates')];
    }

    public function broadcastAs(): string
    {
        return 'shift.changed';
    }

    public function broadcastWith(): array
    {
        return ['shift_id' => $this->shiftId, 'action' => $this->action];
    }
}
