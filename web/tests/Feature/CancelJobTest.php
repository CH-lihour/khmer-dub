<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stopping a job from the page, against a fake worker that follows the API contract.
 */
class CancelJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dubber.url' => 'http://worker.test', 'services.dubber.token' => 'test-token']);
        $this->user = User::factory()->create();
    }

    /**
     * A job of the signed-in user.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function job(array $attributes = []): VideoJob
    {
        return VideoJob::create(['user_id' => $this->user->id, 'original_name' => 'talk.webm', 'path' => 'uploads/a.webm', ...$attributes]);
    }

    private function running(): VideoJob
    {
        return $this->job(['status' => 'processing', 'progress' => 30, 'worker_job_id' => 'w1', 'message' => 'Transcribing speech', 'step_done' => 20, 'step_total' => 60]);
    }

    private function cancel(VideoJob $job): TestResponse
    {
        return $this->actingAs($this->user)->postJson(route('dub.cancel', $job));
    }

    public function test_a_job_the_queue_has_not_started_ends_without_the_worker(): void
    {
        Http::fake();
        $job = $this->job(['message' => 'Waiting in the queue']);

        $this->cancel($job)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('message', 'Cancelled')->assertJsonPath('cancel_url', null);

        Http::assertNothingSent();
        $this->assertTrue($job->fresh()->isFinished());
    }

    public function test_a_running_job_says_it_is_cancelling_until_the_worker_stops_it(): void
    {
        Http::fake(['worker.test/jobs/w1/cancel' => Http::response(['status' => 'processing'])]);
        $job = $this->running();

        $this->cancel($job)->assertOk()
            ->assertJsonPath('status', 'processing')->assertJsonPath('message', 'Cancelling')
            ->assertJsonPath('step_total', null)->assertJsonPath('cancel_url', null);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->hasHeader('x-token', 'test-token'));
        $this->assertTrue($job->fresh()->cancel_requested);
    }

    public function test_a_job_in_line_on_the_worker_stops_at_once(): void
    {
        Http::fake(['worker.test/jobs/w1/cancel' => Http::response(['status' => 'cancelled'])]);

        $this->cancel($this->running())->assertJsonPath('status', 'cancelled')->assertJsonPath('step_total', null);
    }

    public function test_a_reviewed_job_goes_back_to_review_and_keeps_its_old_video(): void
    {
        Http::fake(['worker.test/jobs/w1/cancel' => Http::response(['status' => 'review'])]);
        $job = $this->job(['status' => 'queued', 'progress' => 50, 'worker_job_id' => 'w1', 'video_path' => 'results/1/dubbed.mp4', 'srt_path' => 'results/1/khmer.srt']);

        $this->cancel($job)->assertJsonPath('status', 'review')->assertJsonPath('progress', 50)
            ->assertJsonPath('review_url', route('dub.review', $job));

        $this->assertTrue($job->fresh()->hasPlayableVideo());
        $this->assertFalse($job->fresh()->cancel_requested);
    }

    public function test_a_job_the_worker_lost_is_over(): void
    {
        Http::fake(['worker.test/jobs/w1/cancel' => Http::response(['detail' => 'Unknown job id.'], 404)]);

        $this->cancel($this->running())->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_a_job_that_just_finished_on_the_worker_goes_on(): void
    {
        Http::fake(['worker.test/jobs/w1/cancel' => Http::response(['detail' => 'Nothing to cancel.'], 409)]);
        $job = $this->running();

        $this->cancel($job)->assertOk()->assertJsonPath('status', 'processing')->assertJsonPath('message', 'Transcribing speech');

        $this->assertFalse($job->fresh()->cancel_requested);
    }

    public function test_an_unreachable_worker_is_explained_and_the_job_can_be_cancelled_again(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6'));
        $job = $this->running();

        $this->cancel($job)->assertStatus(503)->assertJsonPath('message', 'The dubbing worker could not be reached. Check that it is running: docker compose ps worker');

        $this->assertFalse($job->fresh()->cancel_requested);
    }

    public function test_a_stopped_job_is_left_alone(): void
    {
        Http::fake();
        $job = $this->job(['status' => 'done', 'progress' => 100]);

        $this->cancel($job)->assertOk()->assertJsonPath('status', 'done');

        Http::assertNothingSent();
    }

    public function test_only_the_owner_can_cancel(): void
    {
        Http::fake();
        $job = $this->running();

        $this->actingAs(User::factory()->create())->postJson(route('dub.cancel', $job))->assertForbidden();

        Http::assertNothingSent();
        $this->assertFalse($job->fresh()->cancel_requested);
    }

    public function test_the_page_offers_cancel_only_while_it_can_work(): void
    {
        $status = fn (VideoJob $job) => $this->actingAs($this->user)->getJson(route('dub.status', $job));

        $job = $this->job(['progress' => 15]);
        $status($job)->assertJsonPath('cancel_url', route('dub.cancel', $job));
        $job->update(['status' => 'processing', 'progress' => 99]);
        $status($job)->assertJsonPath('cancel_url', null);
        $job->update(['status' => 'review', 'progress' => 50]);
        $status($job)->assertJsonPath('cancel_url', null);
    }

    public function test_the_page_knows_whether_cancelling_keeps_the_edits(): void
    {
        $status = fn (VideoJob $job) => $this->actingAs($this->user)->getJson(route('dub.status', $job));

        $status($this->running())->assertJsonPath('cancel_keeps_edits', false);
        $status($this->job(['status' => 'processing', 'progress' => 60, 'worker_job_id' => 'w1', 'language' => 'en']))->assertJsonPath('cancel_keeps_edits', true);
    }

    public function test_a_saved_review_starts_with_no_old_cancel_request(): void
    {
        Queue::fake();
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['status' => 'queued'])]);
        $job = $this->job(['status' => 'review', 'progress' => 50, 'worker_job_id' => 'w1', 'cancel_requested' => true]);

        $this->actingAs($this->user)->post(route('dub.review.save', $job), ['km' => [0 => 'សួស្តី']])->assertRedirect();

        $this->assertFalse($job->fresh()->cancel_requested);
    }
}
