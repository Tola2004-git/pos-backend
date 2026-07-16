<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// See OrderChanged - same "signal only, no data" approach (and same
// ShouldBroadcastNow reasoning) on the same public channel, just for table
// status changes (clear, move, hold, etc).
class TableChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $tableId, public string $action)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('pos-updates')];
    }

    public function broadcastAs(): string
    {
        return 'table.changed';
    }

    public function broadcastWith(): array
    {
        return ['table_id' => $this->tableId, 'action' => $this->action];
    }
}
