<?php

namespace App\Events;

use App\Models\VideoJob;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A video job's progress changed. Sent at once to its owner's private channel (not queued: the
 * queue is busy with the video itself), so the page moves without asking for the status.
 */
class VideoJobUpdated implements ShouldBroadcastNow
{
    public function __construct(public VideoJob $job) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->job->user_id}")];
    }

    public function broadcastAs(): string
    {
        return 'job.updated';
    }

    /**
     * What changes while a job runs. The message is the stored English one; the page puts it in its
     * own language. Anything else (links, errors) the page asks for once the job stops.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $job = $this->job;

        return [
            'id' => $job->id, 'status' => $job->status, 'progress' => $job->progress, 'message' => $job->message,
            'step_done' => $job->step_done, 'step_total' => $job->step_total, 'cancelling' => $job->isRunning() && $job->cancel_requested,
        ];
    }
}
