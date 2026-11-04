<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Editing the Khmer of a job that waits for review.
 */
class ReviewPageTest extends TestCase
{
    use RefreshDatabase;

    private const LINES = [
        ['id' => 0, 'start' => 1.2, 'end' => 4.8, 'text' => 'Hello there', 'km' => 'សួស្តី'],
        ['id' => 1, 'start' => 65.0, 'end' => 67.0, 'text' => 'Goodbye', 'km' => 'លា'],
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dubber.url' => 'http://worker.test', 'services.dubber.token' => 'test-token']);
        Storage::fake('local');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * A job of `$owner` (default: the signed-in user) that waits for review on the worker as "w1".
     *
     * @param  array<string, mixed>  $attributes
     */
    private function waiting(array $attributes = [], ?User $owner = null): VideoJob
    {
        return ($owner ?? $this->user)->videoJobs()->create([
            'original_name' => 'talk.mp4', 'path' => 'uploads/abc.mp4', 'review' => true, 'status' => 'review',
            'progress' => 50, 'message' => 'Waiting for your review', 'worker_job_id' => 'w1', ...$attributes,
        ]);
    }

    public function test_upload_can_ask_for_a_review(): void
    {
        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4'), 'review' => '1'], ['Accept' => 'application/json'])->assertCreated();
        $this->assertTrue(VideoJob::sole()->review);
    }

    public function test_review_is_off_unless_asked_for(): void
    {
        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertFalse(VideoJob::sole()->review);
    }

    public function test_status_links_to_the_review(): void
    {
        $job = $this->waiting();

        $this->getJson(route('dub.status', $job))->assertOk()->assertJson(['status' => 'review', 'review_url' => route('dub.review', $job)]);
        $this->get('/dub')->assertOk()->assertSee(route('dub.review', $job))->assertSee('Needs review');
    }

    public function test_review_page_shows_the_original_and_the_khmer_of_each_line(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['segments' => self::LINES])]);

        $this->get(route('dub.review', $this->waiting()))->assertOk()
            ->assertSee('Hello there')->assertSee('Goodbye')
            ->assertSee('name="km[0]"', false)->assertSee('name="km[1]"', false)
            ->assertSee('សួស្តី')->assertSee('លា')
            ->assertSee('0:01 – 0:04')->assertSee('1:05 – 1:07')
            ->assertSee('Save and make the voice');

        Http::assertSent(fn (Request $request) => $request->hasHeader('x-token', 'test-token'));
    }

    public function test_subtitle_only_review_says_so(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['segments' => self::LINES])]);

        $this->get(route('dub.review', $this->waiting(['dub' => false])))->assertOk()->assertSee('Save the subtitles')->assertDontSee('make the voice');
    }

    public function test_a_voice_failure_is_shown_on_the_review_page(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['segments' => self::LINES])]);

        $this->get(route('dub.review', $this->waiting(['error' => 'The voice service could not be reached.'])))->assertOk()
            ->assertSee('The voice service could not be reached.')->assertSee('Save again to retry');
    }

    public function test_saving_sends_the_edits_and_queues_the_job_again(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['status' => 'queued'])]);
        $job = $this->waiting(['error' => 'An earlier failure.']);

        $this->post(route('dub.review.save', $job), ['km' => [0 => 'ជំរាបសួរ', 1 => '']])
            ->assertRedirect(route('dub.index', ['job' => $job->id]));

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'http://worker.test/jobs/w1/segments'
            && $request->hasHeader('x-token', 'test-token')
            && $request['segments'] === [['id' => 0, 'km' => 'ជំរាបសួរ'], ['id' => 1, 'km' => '']]);
        $job->refresh();
        $this->assertSame(['queued', 'Waiting in the queue', null, 50], [$job->status, $job->message, $job->error, $job->progress]);
        Queue::assertPushed(ProcessVideoJob::class, fn (ProcessVideoJob $pushed) => $pushed->videoJob->is($job));
    }

    public function test_a_worker_refusal_keeps_the_typed_text(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['detail' => 'Every line is empty. Keep the Khmer text of at least one line.'], 422)]);
        $job = $this->waiting();

        $this->from(route('dub.review', $job))->post(route('dub.review.save', $job), ['km' => [0 => '', 1 => '']])
            ->assertRedirect(route('dub.review', $job))
            ->assertSessionHasErrors(['review' => 'Every line is empty. Keep the Khmer text of at least one line.'])
            ->assertSessionHasInput('km');

        $this->assertSame('review', $job->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_an_unreachable_worker_keeps_the_job_in_review(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6'));
        $job = $this->waiting();

        $this->from(route('dub.review', $job))->post(route('dub.review.save', $job), ['km' => [0 => 'ក']])
            ->assertSessionHasErrors('review');
        $this->get(route('dub.review', $job))->assertRedirect(route('dub.index', ['job' => $job->id]))->assertSessionHasErrors('review');

        $this->assertSame('review', $job->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_lines_must_be_short_enough(): void
    {
        Http::fake();
        $job = $this->waiting();

        $this->from(route('dub.review', $job))->post(route('dub.review.save', $job), ['km' => [0 => str_repeat('ក', 2001)]])
            ->assertSessionHasErrors(['km.0' => 'A line can have at most 2000 characters.']);
        Http::assertNothingSent();
    }

    public function test_a_job_not_in_review_goes_back_to_the_page(): void
    {
        Http::fake();
        $job = $this->waiting(['status' => 'done']);

        $this->get(route('dub.review', $job))->assertRedirect(route('dub.index', ['job' => $job->id]));
        $this->post(route('dub.review.save', $job), ['km' => [0 => 'ក']])->assertRedirect(route('dub.index', ['job' => $job->id]));

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_another_user_cannot_read_or_change_a_review(): void
    {
        Http::fake();
        $job = $this->waiting([], User::factory()->create());

        $this->get(route('dub.review', $job))->assertForbidden();
        $this->post(route('dub.review.save', $job), ['km' => [0 => 'ក']])->assertForbidden();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $job = $this->waiting();
        auth()->logout();

        $this->get(route('dub.review', $job))->assertRedirect(route('login'));
        $this->post(route('dub.review.save', $job), [])->assertRedirect(route('login'));
    }
}
