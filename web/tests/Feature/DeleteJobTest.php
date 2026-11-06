<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Deleting a video from the page, against a fake worker that follows the API contract.
 */
class DeleteJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dubber.url' => 'http://worker.test', 'services.dubber.token' => 'test-token']);
        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    /**
     * A finished video of the signed-in user, with its files.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function job(array $attributes = []): VideoJob
    {
        $disk = Storage::disk('local');
        $disk->put('uploads/a.webm', 'video bytes');
        $job = VideoJob::create(['user_id' => $this->user->id, 'original_name' => 'talk.webm', 'path' => 'uploads/a.webm', 'status' => 'done', 'progress' => 100, 'worker_job_id' => 'w1', ...$attributes]);
        $disk->put("results/{$job->id}/dubbed.mp4", 'fake mp4');
        $disk->put("results/{$job->id}/khmer.srt", 'subtitles');

        return $job;
    }

    private function remove(VideoJob $job): TestResponse
    {
        return $this->actingAs($this->user)->deleteJson(route('dub.destroy', $job));
    }

    private function assertKept(VideoJob $job): void
    {
        $this->assertNotNull($job->fresh());
        Storage::disk('local')->assertExists(['uploads/a.webm', "results/{$job->id}/dubbed.mp4"]);
    }

    public function test_a_finished_video_goes_from_the_worker_and_from_here(): void
    {
        Http::fake(['worker.test/jobs/w1' => Http::response(['status' => 'deleted'])]);
        $job = $this->job();

        $this->remove($job)->assertNoContent();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'http://worker.test/jobs/w1' && $request->hasHeader('x-token', 'test-token'));
        $this->assertNull($job->fresh());
        Storage::disk('local')->assertMissing(['uploads/a.webm', "results/{$job->id}/dubbed.mp4", "results/{$job->id}/khmer.srt"]);
    }

    public function test_a_video_never_sent_to_the_worker_needs_no_worker(): void
    {
        Http::fake();
        $job = $this->job(['status' => 'cancelled', 'progress' => 0, 'worker_job_id' => null]);

        $this->remove($job)->assertNoContent();

        Http::assertNothingSent();
        $this->assertNull($job->fresh());
    }

    public function test_a_job_the_worker_no_longer_has_is_still_deleted(): void
    {
        Http::fake(['worker.test/jobs/w1' => Http::response(['detail' => 'Unknown job id.'], 404)]);
        $job = $this->job();

        $this->remove($job)->assertNoContent();

        $this->assertNull($job->fresh());
    }

    public function test_a_running_video_is_kept_and_needs_cancelling_first(): void
    {
        Http::fake();
        foreach (['queued', 'processing'] as $status) {
            $job = $this->job(['status' => $status, 'progress' => 40]);

            $this->remove($job)->assertStatus(409)->assertJsonPath('message', 'Cancel this video before deleting it.');

            $this->assertKept($job);
        }
        Http::assertNothingSent();
    }

    public function test_nothing_is_deleted_here_if_the_worker_refuses(): void
    {
        Http::fake(['worker.test/jobs/w1' => Http::response(['detail' => 'This job is still queued or running. Cancel it first.'], 409)]);
        $job = $this->job();

        $this->remove($job)->assertStatus(503)->assertJsonPath('message', 'This job is still queued or running. Cancel it first.');

        $this->assertKept($job);
    }

    public function test_nothing_is_deleted_here_if_the_worker_cannot_be_reached(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6'));
        $job = $this->job();

        $this->remove($job)->assertStatus(503)->assertJsonPath('message', 'The dubbing worker could not be reached. Check that it is running: docker compose ps worker');

        $this->assertKept($job);
    }

    public function test_only_the_owner_can_delete(): void
    {
        Http::fake();
        $job = $this->job();

        $this->actingAs(User::factory()->create())->deleteJson(route('dub.destroy', $job))->assertForbidden();

        Http::assertNothingSent();
        $this->assertKept($job);
    }

    public function test_the_page_offers_delete_only_on_stopped_videos(): void
    {
        $status = fn (VideoJob $job) => $this->actingAs($this->user)->getJson(route('dub.status', $job));
        $job = $this->job();

        $status($job)->assertJsonPath('delete_url', route('dub.destroy', $job));
        $this->actingAs($this->user)->get('/dub')->assertOk()
            ->assertSee('id="delete-dialog"', false)->assertSee('Delete talk.webm')->assertSee('Delete this video?');

        $job->update(['status' => 'processing']);
        $status($job)->assertJsonPath('delete_url', null);
        $this->actingAs($this->user)->get('/dub')->assertOk()->assertDontSee('Delete talk.webm');
    }

    public function test_a_queued_job_whose_video_was_deleted_ends_quietly(): void
    {
        $this->assertTrue((new ProcessVideoJob($this->job()))->deleteWhenMissingModels);
    }
}
