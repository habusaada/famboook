<?php

namespace Tests\Support;

use App\Contracts\NotificationPublicationDispatcher;
use App\Models\NotificationMessage;
use Illuminate\Support\Facades\DB;

/**
 * FB-NOTIFY-01B.2b.4 test double for the publication hand-off: records which
 * message was handed off, its committed status at that moment and the
 * transaction level — and publishes nothing.
 */
final class RecordingPublicationDispatcher implements NotificationPublicationDispatcher
{
    /** @var list<array{id: int, status: string|null, level: int}> */
    public array $dispatched = [];

    public function dispatch(NotificationMessage $message): void
    {
        $this->dispatched[] = [
            'id' => (int) $message->getKey(),
            'status' => DB::table('notification_messages')->where('id', $message->getKey())->value('status'),
            'level' => DB::transactionLevel(),
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_column($this->dispatched, 'id');
    }
}
