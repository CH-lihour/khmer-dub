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
            ->assertRedirect(route('dub.index', ['job' => $job->id]))
            ->assertSessionHas('notice', 'Saved. Making the Khmer voice…');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'http://worker.test/jobs/w1/segments'
            && $request->hasHeader('x-token', 'test-token')
            && $request['segments'] === [['id' => 0, 'km' => 'ជំរាបសួរ'], ['id' => 1, 'km' => '']]);
        $job->refresh();
        $this->assertSame(['queued', 'Waiting in the queue', null, 50], [$job->status, $job->message, $job->error, $job->progress]);
        Queue::assertPushed(ProcessVideoJob::class, fn (ProcessVideoJob $pushed) => $pushed->videoJob->is($job));
    }

    /**
     * A finished dubbed video on the worker as "w1", with its upload and results here.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finished(array $attributes = []): VideoJob
    {
        Storage::disk('local')->put('uploads/abc.mp4', 'video bytes');
        Storage::disk('local')->put('results/1/dubbed.mp4', 'old video');

        return $this->waiting([
            'status' => 'done', 'progress' => 100, 'message' => 'Done', 'gender' => 'female', 'original_volume' => 0.15,
            'video_path' => 'results/1/dubbed.mp4', 'srt_path' => 'results/1/khmer.srt', ...$attributes,
        ]);
    }

    public function test_a_finished_video_can_be_opened_for_editing(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['segments' => [['id' => 0, 'start' => 1.0, 'end' => 2.0, 'text' => 'Hello', 'km' => 'សួស្តី']]])]);

        $this->get(route('dub.review', $this->finished()))->assertOk()
            ->assertSee('Save and regenerate')->assertSee('The current video stays')->assertSee('សួស្តី')
            ->assertSee('value="female" checked', false)->assertSee('step="0.05" value="0.15"', false)->assertSee('15%');
    }

    public function test_saving_a_finished_video_sends_it_again_then_the_edits_and_keeps_the_old_one_for_now(): void
    {
        Http::fake([
            'worker.test/jobs/w1/reopen' => Http::response(['status' => 'review']),
            'worker.test/jobs/w1/segments' => Http::response(['status' => 'queued']),
        ]);
        $job = $this->finished(['error' => 'An earlier failure.']);

        $this->post(route('dub.review.save', $job), ['km' => [0 => 'ថ្មី'], 'gender' => 'male', 'original_volume' => '0.4'])
            ->assertRedirect(route('dub.index', ['job' => $job->id]))
            ->assertSessionHas('notice', 'Saved. Making the new video…');

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $this->assertSame(
            ['POST http://worker.test/jobs/w1/reopen', 'PUT http://worker.test/jobs/w1/segments'],
            $sent->map(fn (Request $request) => "{$request->method()} {$request->url()}")->all(),
        );
        $this->assertTrue($sent[0]->isMultipart() && $sent[0]->hasFile('file', null, 'abc.mp4'));
        $this->assertSame(['segments' => [['id' => 0, 'km' => 'ថ្មី']], 'gender' => 'male', 'original_volume' => 0.4], $sent[1]->data());
        $job->refresh();
        $this->assertSame(
            ['queued', 50, null, 'male', 0.4, 'results/1/dubbed.mp4'],
            [$job->status, $job->progress, $job->error, $job->gender, $job->original_volume, $job->video_path],
        );
        Storage::disk('local')->assertExists('results/1/dubbed.mp4');
        Queue::assertPushed(ProcessVideoJob::class, fn (ProcessVideoJob $pushed) => $pushed->videoJob->is($job));
    }

    public function test_a_refused_regeneration_leaves_the_finished_video_as_it_was(): void
    {
        Http::fake([
            'worker.test/jobs/w1/reopen' => Http::response(['status' => 'review']),
            'worker.test/jobs/w1/segments' => Http::response(['detail' => 'Every line is empty. Keep the Khmer text of at least one line.'], 422),
        ]);
        $job = $this->finished();

        $this->from(route('dub.review', $job))->post(route('dub.review.save', $job), ['km' => [0 => '']])
            ->assertRedirect(route('dub.review', $job))->assertSessionHasErrors('review')->assertSessionHasInput('km');

        $this->assertSame(['done', 100, 'female'], [$job->fresh()->status, $job->fresh()->progress, $job->fresh()->gender]);
        Queue::assertNothingPushed();
    }

    public function test_regenerating_needs_the_upload(): void
    {
        Http::fake();
        $job = $this->finished();
        Storage::disk('local')->delete('uploads/abc.mp4');

        $this->from(route('dub.review', $job))->post(route('dub.review.save', $job), ['km' => [0 => 'ក']])
            ->assertSessionHasErrors(['review' => 'The uploaded video is missing on the server. Please upload it again.']);

        Http::assertNothingSent();
    }

    public function test_regenerating_needs_the_worker_job(): void
    {
        Http::fake(['worker.test/jobs/w1/reopen' => Http::response(['detail' => 'Unknown job id.'], 404)]);
        $job = $this->finished();

        $this->from(route('dub.review', $job))->post(route('dub.review.save', $job), ['km' => [0 => 'ក']])
            ->assertSessionHasErrors(['review' => 'The worker no longer has this job. Please upload the video again.']);

        $this->assertSame('done', $job->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_bad_choices_are_refused_before_the_video_is_sent(): void
    {
        Http::fake();
        $job = $this->finished();

        $this->post(route('dub.review.save', $job), ['km' => [0 => 'ក'], 'gender' => 'robot'])->assertSessionHasErrors('gender');
        $this->post(route('dub.review.save', $job), ['km' => [0 => 'ក'], 'original_volume' => '2'])->assertSessionHasErrors('original_volume');

        Http::assertNothingSent();
    }

    public function test_only_finished_dubbed_videos_can_be_regenerated(): void
    {
        $subtitles = $this->waiting(['status' => 'done', 'dub' => false, 'srt_path' => 'results/1/khmer.srt']);
        $running = $this->finished(['status' => 'processing']);

        foreach ([$subtitles, $running] as $job) {
            $this->get(route('dub.review', $job))->assertRedirect(route('dub.index', ['job' => $job->id]));
            $this->post(route('dub.review.save', $job), ['km' => [0 => 'ក']])->assertRedirect(route('dub.index', ['job' => $job->id]));
        }
        Queue::assertNothingPushed();
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

    public function test_the_review_page_plays_the_upload_line_by_line(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['segments' => [
            ['id' => 0, 'start' => 1.0, 'end' => 2.5, 'text' => 'Hello', 'km' => 'សួស្តី'],
            ['id' => 1, 'start' => 3.0, 'end' => 4.0, 'text' => 'Bye', 'km' => 'លាហើយ'],
        ]])]);
        $job = $this->waiting();

        $this->get(route('dub.review', $job))->assertOk()
            ->assertSee('src="'.route('dub.preview', [$job, 'original']).'"', false)
            ->assertSee('data-start="1" data-end="2.5" data-next="3"', false)
            ->assertSee('data-start="3" data-end="4" data-next=""', false)
            ->assertSee('▶ 0:01 – 0:02');
    }

    public function test_the_upload_can_be_played_only_by_its_owner(): void
    {
        Storage::disk('local')->put('uploads/abc.mp4', 'video bytes');
        $job = $this->waiting();

        $response = $this->get(route('dub.preview', [$job, 'original']))->assertOk();
        $this->assertSame('video bytes', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get(route('dub.preview', [$this->waiting([], User::factory()->create()), 'original']))->assertForbidden();
        Storage::disk('local')->delete('uploads/abc.mp4');
        $this->get(route('dub.preview', [$job, 'original']))->assertNotFound();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $job = $this->waiting();
        auth()->logout();

        $this->get(route('dub.review', $job))->assertRedirect(route('login'));
        $this->post(route('dub.review.save', $job), [])->assertRedirect(route('login'));
    }
}
