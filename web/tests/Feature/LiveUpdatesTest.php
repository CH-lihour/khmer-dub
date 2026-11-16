<?php

namespace Tests\Feature;

use App\Events\VideoJobUpdated;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Live progress through Reverb: what is sent, to whom, and that a stopped Reverb changes nothing else.
 */
class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        // Channels belong to the broadcaster that was the default at boot (null in phpunit.xml).
        require base_path('routes/channels.php');
        $this->user = User::factory()->create();
    }

    private function job(): VideoJob
    {
        return $this->user->videoJobs()->create(['original_name' => 'a.mp4', 'path' => 'uploads/a.mp4', 'status' => 'processing']);
    }

    public function test_a_progress_change_is_sent_to_the_owner(): void
    {
        Event::fake([VideoJobUpdated::class]);
        $job = $this->job();

        $job->update(['progress' => 45, 'message' => 'Translating to Khmer', 'step_done' => 3, 'step_total' => 8]);

        Event::assertDispatched(VideoJobUpdated::class, function (VideoJobUpdated $event) use ($job) {
            return $event->broadcastOn()[0]->name === "private-users.{$this->user->id}"
                && $event->broadcastAs() === 'job.updated'
                && $event->broadcastWith() === ['id' => $job->id, 'status' => 'processing', 'progress' => 45, 'message' => 'Translating to Khmer',
                    'step_done' => 3, 'step_total' => 8, 'cancelling' => false];
        });
    }

    public function test_nothing_is_sent_for_other_changes_or_with_broadcasting_off(): void
    {
        Event::fake([VideoJobUpdated::class]);
        $job = $this->job();

        $job->update(['srt_path' => 'results/1/khmer.srt']);
        config(['broadcasting.default' => 'null']);
        $job->update(['progress' => 50]);

        Event::assertNotDispatched(VideoJobUpdated::class);
    }

    public function test_a_stopped_reverb_never_fails_the_job_and_is_not_tried_again_for_a_minute(): void
    {
        Broadcast::shouldReceive('queue')->once()->andThrow(new BroadcastException('cURL error 7: Failed to connect'));
        $job = $this->job();

        $job->update(['progress' => 20]);
        $job->update(['progress' => 30]);

        $this->assertSame(30, $job->fresh()->progress);
        $this->travel(61)->seconds();
        Broadcast::shouldReceive('queue')->once();
        $job->update(['progress' => 40]);
    }

    public function test_the_page_listens_to_its_own_channel_only_when_live_updates_are_on(): void
    {
        $this->actingAs($this->user);

        $this->get('/dub')->assertOk()->assertSee(asset('js/live.js'))
            ->assertSee('"channel":"private-users.'.$this->user->id.'"', false)->assertSee('"key":"test-key"', false);

        config(['broadcasting.default' => 'null']);
        $this->get('/dub')->assertOk()->assertDontSee(asset('js/live.js'));
    }

    public function test_only_the_owner_may_listen_to_a_channel(): void
    {
        $this->actingAs($this->user);
        $other = User::factory()->create();

        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$this->user->id}"])
            ->assertOk()->assertJsonStructure(['auth']);
        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$other->id}"])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_listen(): void
    {
        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$this->user->id}"])
            ->assertForbidden();
    }
}
