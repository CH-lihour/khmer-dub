<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A waiting video says how many videos, anyone's, will be worked on before it.
 */
class QueuePositionTest extends TestCase
{
    use RefreshDatabase;

    private function job(User $owner, string $status): VideoJob
    {
        return $owner->videoJobs()->create(['original_name' => "{$status}.mp4", 'path' => 'uploads/a.mp4', 'status' => $status]);
    }

    public function test_a_waiting_video_counts_the_running_one_and_those_queued_before_it(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->travelTo(now()->subMinutes(3));
        $this->job($other, VideoJob::PROCESSING);
        $this->job($other, VideoJob::DONE);
        $this->travelBack();
        $this->travelTo(now()->subMinutes(2));
        $this->job($other, VideoJob::QUEUED);
        $this->travelBack();
        $mine = $this->job($me, VideoJob::QUEUED);
        $this->travelTo(now()->addMinute());
        $later = $this->job($other, VideoJob::QUEUED);

        $this->actingAs($me)->getJson(route('dub.status', $mine))->assertOk()->assertJson(['ahead' => 2]);
        $this->assertSame(3, $later->aheadInQueue());
    }

    public function test_only_a_waiting_video_has_a_place(): void
    {
        $me = User::factory()->create();
        $running = $this->job($me, VideoJob::PROCESSING);

        $this->actingAs($me)->getJson(route('dub.status', $running))->assertOk()->assertJson(['ahead' => null]);
    }
}
