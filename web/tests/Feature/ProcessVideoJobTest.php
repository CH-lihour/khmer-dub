<?php

namespace Tests\Feature;

use App\Exceptions\DubbingFailed;
use App\Jobs\ProcessVideoJob;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * ProcessVideoJob against a fake worker that follows the API contract.
 */
class ProcessVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private const SRT = "1\n00:00:01,000 --> 00:00:02,500\nសួស្តី\n";

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dubber.url' => 'http://worker.test', 'services.dubber.token' => 'test-token']);
        Storage::fake('local');
        Sleep::fake();
    }

    /**
     * An uploaded video and its row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upload(array $attributes = []): VideoJob
    {
        Storage::disk('local')->put('uploads/abc123.webm', 'video bytes');

        return VideoJob::create(['original_name' => 'talk.webm', 'path' => 'uploads/abc123.webm', 'gender' => 'male', 'original_volume' => 0.35, ...$attributes]);
    }

    /**
     * A worker that accepts the upload as job "w1", answers the status calls in order, and sends the results.
     *
     * @param  list<array<string, mixed>>  $statuses
     * @param  array<string, mixed>  $override  responses that replace the defaults, by URL
     */
    private function fakeWorker(array $statuses, array $override = []): void
    {
        $sequence = Http::sequence();
        foreach ($statuses as $status) {
            $sequence->push($status + ['progress' => 0, 'message' => null, 'error' => null, 'language' => null]);
        }
        Http::fake($override + [
            'worker.test/jobs' => Http::response(['job_id' => 'w1']),
            'worker.test/jobs/w1' => $sequence,
            'worker.test/jobs/w1/download/srt' => Http::response(self::SRT, 200, ['Content-Type' => 'application/x-subrip']),
            'worker.test/jobs/w1/download/video' => Http::response('fake mp4', 200, ['Content-Type' => 'video/mp4']),
        ]);
    }

    /**
     * Run the job the way the queue does with tries=1: an exception goes to failed().
     */
    private function process(VideoJob $row): ?Throwable
    {
        $job = new ProcessVideoJob($row);
        try {
            $job->handle();
        } catch (Throwable $exception) {
            $job->failed($exception);

            return $exception;
        }

        return null;
    }

    public function test_sends_the_upload_follows_the_progress_and_downloads_both_results(): void
    {
        $row = $this->upload();
        $this->fakeWorker([
            ['status' => 'queued', 'message' => 'Waiting in the queue'],
            ['status' => 'processing', 'progress' => 45, 'message' => 'Translating to Khmer'],
            ['status' => 'done', 'progress' => 100, 'message' => 'Done', 'language' => 'en'],
        ]);
        $seen = [];
        VideoJob::updated(function (VideoJob $job) use (&$seen) {
            $seen[] = [$job->status, $job->progress, $job->message];
        });

        $this->assertNull($this->process($row));

        $row->refresh();
        $this->assertSame(['done', 100, 'Done', null, 'en', 'w1'], [$row->status, $row->progress, $row->message, $row->error, $row->language, $row->worker_job_id]);
        $this->assertSame(["results/{$row->id}/khmer.srt", "results/{$row->id}/dubbed.mp4"], [$row->srt_path, $row->video_path]);
        $this->assertSame(self::SRT, Storage::disk('local')->get($row->srt_path));
        $this->assertSame('fake mp4', Storage::disk('local')->get($row->video_path));
        $this->assertContains(['queued', 0, 'Waiting in the queue'], $seen);
        $this->assertContains(['processing', 45, 'Translating to Khmer'], $seen);
        $this->assertContains(['processing', 99, 'Downloading the results'], $seen);
        Sleep::assertSleptTimes(2);

        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->pluck('contents', 'name');

            return $request->url() === 'http://worker.test/jobs' && $request->method() === 'POST'
                && $request->hasHeader('x-token', 'test-token') && $request->isMultipart()
                && $request->hasFile('file', null, 'abc123.webm')
                && $fields['gender'] === 'male' && $fields['dub'] === 'true' && $fields['original_volume'] === '0.35';
        });
        Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/jobs/w1' && $request->hasHeader('x-token', 'test-token'));
    }

    public function test_subtitles_only_job_skips_the_video(): void
    {
        $row = $this->upload(['dub' => false]);
        $this->fakeWorker([['status' => 'done', 'progress' => 100, 'message' => 'Done', 'language' => 'th']]);

        $this->assertNull($this->process($row));

        $row->refresh();
        $this->assertSame(['done', 'th', null], [$row->status, $row->language, $row->video_path]);
        Storage::disk('local')->assertExists($row->srt_path);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/jobs' && collect($request->data())->pluck('contents', 'name')['dub'] === 'false');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/download/video'));
        Sleep::assertNeverSlept();
    }

    public function test_a_job_that_waits_for_review_ends_without_holding_the_queue(): void
    {
        $row = $this->upload(['review' => true]);
        $this->fakeWorker([
            ['status' => 'processing', 'progress' => 45, 'message' => 'Translating to Khmer'],
            ['status' => 'review', 'progress' => 50, 'message' => 'Waiting for your review', 'language' => 'en'],
        ]);

        $this->assertNull($this->process($row));

        $row->refresh();
        $this->assertSame(['review', 50, 'Waiting for your review', 'en', 'w1', null], [$row->status, $row->progress, $row->message, $row->language, $row->worker_job_id, $row->srt_path]);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/jobs' && collect($request->data())->pluck('contents', 'name')['review'] === 'true');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/download/'));
    }

    public function test_a_saved_review_follows_the_same_worker_job_to_the_end(): void
    {
        $row = $this->upload(['review' => true, 'status' => 'queued', 'progress' => 50, 'worker_job_id' => 'w1', 'language' => 'en']);
        $this->fakeWorker([
            ['status' => 'processing', 'progress' => 60, 'message' => 'Making the Khmer voice'],
            ['status' => 'done', 'progress' => 100, 'message' => 'Done', 'language' => 'en'],
        ]);

        $this->assertNull($this->process($row));

        $row->refresh();
        $this->assertSame(['done', 100, 'en'], [$row->status, $row->progress, $row->language]);
        Storage::disk('local')->assertExists($row->srt_path);
        Http::assertNotSent(fn (Request $request) => $request->url() === 'http://worker.test/jobs'); // not uploaded twice
    }

    public function test_a_voice_failure_after_review_goes_back_to_review_with_the_error(): void
    {
        $row = $this->upload(['review' => true, 'status' => 'queued', 'progress' => 50, 'worker_job_id' => 'w1']);
        $this->fakeWorker([
            ['status' => 'review', 'progress' => 50, 'message' => 'Waiting for your review', 'language' => 'en', 'error' => 'The voice service could not be reached.'],
        ]);

        $this->assertNull($this->process($row));

        $row->refresh();
        $this->assertSame(['review', 'The voice service could not be reached.'], [$row->status, $row->error]);
    }

    public function test_a_video_made_again_replaces_the_old_results(): void
    {
        Storage::disk('local')->put('results/1/dubbed.mp4', 'old video');
        Storage::disk('local')->put('results/1/khmer.srt', 'old subtitles');
        $row = $this->upload(['status' => 'queued', 'progress' => 50, 'worker_job_id' => 'w1', 'video_path' => 'results/1/dubbed.mp4', 'srt_path' => 'results/1/khmer.srt']);
        $this->fakeWorker([['status' => 'done', 'progress' => 100, 'language' => 'en']]);

        $this->assertNull($this->process($row));

        $row->refresh();
        $this->assertSame(['done', null], [$row->status, $row->error]);
        $this->assertSame('fake mp4', Storage::disk('local')->get('results/1/dubbed.mp4'));
        $this->assertSame(self::SRT, Storage::disk('local')->get('results/1/khmer.srt'));
        Storage::disk('local')->assertMissing('results/1/dubbed.mp4.part');
    }

    public function test_a_failed_download_keeps_the_old_results_and_shows_the_reason(): void
    {
        Storage::disk('local')->put('results/1/dubbed.mp4', 'old video');
        Storage::disk('local')->put('results/1/khmer.srt', 'old subtitles');
        $row = $this->upload(['status' => 'queued', 'progress' => 50, 'worker_job_id' => 'w1', 'video_path' => 'results/1/dubbed.mp4', 'srt_path' => 'results/1/khmer.srt']);
        $this->fakeWorker([['status' => 'done', 'progress' => 100, 'language' => 'en']], [
            'worker.test/jobs/w1/download/video' => Http::response(['detail' => 'The result file is no longer on the worker.'], 404),
        ]);

        $this->assertInstanceOf(DubbingFailed::class, $this->process($row));

        $row->refresh();
        $this->assertSame(['done', 100, 'The result file is no longer on the worker.'], [$row->status, $row->progress, $row->error]);
        $this->assertSame('old video', Storage::disk('local')->get('results/1/dubbed.mp4'));
        $this->assertSame('old subtitles', Storage::disk('local')->get('results/1/khmer.srt'));
    }

    public function test_the_worker_refusing_the_video_fails_the_job_with_its_reason(): void
    {
        $row = $this->upload();
        $this->fakeWorker([], ['worker.test/jobs' => Http::response(['detail' => 'The video is 20.0 minutes long; the limit is 15 minutes.'], 422)]);

        $this->assertInstanceOf(DubbingFailed::class, $this->process($row));

        $row->refresh();
        $this->assertSame(['failed', 'Failed', 'The video is 20.0 minutes long; the limit is 15 minutes.'], [$row->status, $row->message, $row->error]);
    }

    public function test_a_failed_worker_job_fails_the_row_with_the_workers_error(): void
    {
        $row = $this->upload();
        $this->fakeWorker([
            ['status' => 'processing', 'progress' => 15, 'message' => 'Transcribing speech'],
            ['status' => 'failed', 'progress' => 15, 'message' => 'Failed', 'error' => 'No speech was found in the video.'],
        ]);

        $this->process($row);

        $row->refresh();
        $this->assertSame(['failed', 15, 'No speech was found in the video.'], [$row->status, $row->progress, $row->error]);
        $this->assertNull($row->srt_path);
    }

    public function test_a_wrong_token_says_which_settings_must_match(): void
    {
        $row = $this->upload();
        $this->fakeWorker([], ['worker.test/jobs' => Http::response(['detail' => 'Wrong or missing x-token.'], 401)]);

        $this->process($row);

        $this->assertStringContainsString('DUBBER_TOKEN in web/.env must equal WORKER_TOKEN', $row->refresh()->error);
    }

    public function test_a_job_the_worker_lost_asks_for_a_new_upload(): void
    {
        $row = $this->upload();
        $this->fakeWorker([], ['worker.test/jobs/w1' => Http::response(['detail' => 'Unknown job id.'], 404)]);

        $this->process($row);

        $this->assertSame('The worker no longer has this job. Please upload the video again.', $row->refresh()->error);
    }

    public function test_an_unreachable_worker_is_explained(): void
    {
        $row = $this->upload();
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: worker'));

        $this->assertInstanceOf(ConnectionException::class, $this->process($row));

        $this->assertStringContainsString('could not be reached', $row->refresh()->error);
    }

    public function test_a_missing_upload_fails_without_calling_the_worker(): void
    {
        $row = VideoJob::create(['original_name' => 'gone.mp4', 'path' => 'uploads/gone.mp4']);
        Http::fake();

        $this->process($row);

        $this->assertStringContainsString('missing on the server', $row->refresh()->error);
        Http::assertNothingSent();
    }

    public function test_a_failed_download_leaves_no_partial_file(): void
    {
        $row = $this->upload();
        $this->fakeWorker([['status' => 'done', 'progress' => 100, 'language' => 'en']], [
            'worker.test/jobs/w1/download/video' => Http::response(['detail' => 'The result file is no longer on the worker.'], 404),
        ]);

        $this->process($row);

        $row->refresh();
        $this->assertSame(['failed', 'The result file is no longer on the worker.'], [$row->status, $row->error]);
        Storage::disk('local')->assertMissing("results/{$row->id}/dubbed.mp4");
    }

    public function test_reasons_for_queue_failures(): void
    {
        $this->assertStringContainsString('longer than one hour', ProcessVideoJob::reason(new TimeoutExceededException('timed out')));
        $this->assertStringContainsString('laravel.log', ProcessVideoJob::reason(new RuntimeException('SQLSTATE secret detail')));
        $this->assertStringNotContainsString('SQLSTATE', ProcessVideoJob::reason(new RuntimeException('SQLSTATE secret detail')));
    }

    public function test_job_settings_match_the_specification(): void
    {
        $job = new ProcessVideoJob(new VideoJob);

        $this->assertSame([3600, 1, 4], [$job->timeout, $job->tries, ProcessVideoJob::POLL_SECONDS]);
    }
}
