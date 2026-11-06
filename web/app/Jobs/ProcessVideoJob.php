<?php

namespace App\Jobs;

use App\Exceptions\DubbingFailed;
use App\Models\VideoJob;
use App\Support\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Sends one uploaded video to the FastAPI worker, copies its progress into the row, and
 * downloads the results into storage/app/private/results/{id}/.
 */
class ProcessVideoJob implements ShouldQueue
{
    use Queueable;

    /** Seconds between two status requests to the worker. */
    public const POLL_SECONDS = 4;

    /** Seconds allowed for sending the upload or downloading one result. */
    private const TRANSFER_SECONDS = 600;

    /** The queue stops the job after one hour. */
    public int $timeout = 3600;

    /** A failed video is not retried; the user uploads it again. */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    /** A video deleted while it waited in the queue has nothing left to do. */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  VideoJob  $videoJob  the row to process ($job is taken by the queue)
     */
    public function __construct(public VideoJob $videoJob) {}

    /**
     * Run the job until the results are here, or until it waits for a person's review.
     *
     * A review can take days, so the job ends there instead of holding the queue. Saving the review
     * dispatches it again, and it goes on following the same worker job. Any exception ends in
     * failed(). New results replace old ones only once both have arrived. A job cancelled while it
     * waited in the queue is not started.
     */
    public function handle(): void
    {
        $job = $this->videoJob;
        if (VideoJob::whereKey($job->id)->where('status', VideoJob::QUEUED)->update(['status' => VideoJob::PROCESSING]) === 0) {
            return;
        }

        if ($job->worker_job_id === null) {
            $job->update(['status' => VideoJob::PROCESSING, 'progress' => 0, 'message' => 'Sending the video to the worker', 'error' => null]);
            $job->update(['worker_job_id' => $this->submit($job)]);
            if ($job->refresh()->cancel_requested) { // asked for while the video was on its way
                $this->worker()->post("/jobs/{$job->worker_job_id}/cancel");
            }
        }

        $workerId = $job->worker_job_id;
        $status = $this->follow($job, $workerId);
        $job->refresh(); // the site may have asked to cancel since this copy was read
        $language = $status['language'] ?? null;
        if ($status['status'] === VideoJob::CANCELLED) {
            $job->markStopped(VideoJob::CANCELLED);

            return;
        }
        if ($status['status'] === 'review') {
            $job->update([
                'status' => VideoJob::REVIEW, 'progress' => (int) ($status['progress'] ?? 0), 'message' => $status['message'] ?? null,
                'language' => $language, 'error' => $status['error'] ?? null, 'step_done' => null, 'step_total' => null, 'cancel_requested' => false,
            ]);

            return;
        }

        $job->update(['progress' => 99, 'message' => 'Downloading the results', 'step_done' => null, 'step_total' => null]);
        $folder = $job->resultsFolder();
        $files = ['srt' => "{$folder}/khmer.srt"] + ($job->dub ? ['video' => "{$folder}/dubbed.mp4"] : []);
        foreach ($files as $kind => $path) {
            $this->download($workerId, $kind, "{$path}.part");
        }
        foreach ($files as $path) {
            Storage::disk('local')->move("{$path}.part", $path);
        }

        $job->update([
            'status' => VideoJob::DONE, 'progress' => 100, 'message' => 'Done', 'language' => $language, 'error' => null,
            'srt_path' => $files['srt'], 'video_path' => $files['video'] ?? null, 'cancel_requested' => false,
        ]);
    }

    /**
     * Mark the row as failed with a reason the user can act on. If making a video again failed, the
     * old one is still good, so the job stays done and only shows the reason.
     */
    public function failed(?Throwable $exception): void
    {
        $outcome = $this->videoJob->video_path !== null
            ? ['status' => VideoJob::DONE, 'progress' => 100, 'message' => 'Done']
            : ['status' => VideoJob::FAILED, 'message' => 'Failed'];
        $this->videoJob->update([...$outcome, 'error' => self::reason($exception), 'step_done' => null, 'step_total' => null]);
    }

    /**
     * The message stored for a failure. Unexpected errors get a general sentence; Laravel logs the details.
     */
    public static function reason(?Throwable $exception): string
    {
        return match (true) {
            $exception instanceof DubbingFailed => $exception->getMessage(),
            $exception instanceof ConnectionException => Worker::UNREACHABLE,
            $exception instanceof TimeoutExceededException => 'Processing took longer than one hour, so it was stopped. Try a shorter video.',
            $exception instanceof MaxAttemptsExceededException => 'The queue stopped while this video was being processed. Please upload it again.',
            default => 'Something went wrong while dubbing the video. The details are in web/storage/logs/laravel.log.',
        };
    }

    /**
     * Send the upload to POST /jobs and return the worker's job id.
     */
    private function submit(VideoJob $job): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($job->path)) {
            throw new DubbingFailed('The uploaded video is missing on the server. Please upload it again.');
        }

        $stream = $disk->readStream($job->path);
        try {
            $response = $this->worker()->timeout(self::TRANSFER_SECONDS)
                ->attach('file', $stream, basename($job->path)) // the random stored name keeps the extension
                ->post('/jobs', [
                    'gender' => $job->gender, 'dub' => $job->dub ? 'true' : 'false', 'review' => $job->review ? 'true' : 'false',
                    'original_volume' => (string) $job->original_volume,
                ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $id = $this->check($response, 'accept the video')->json('job_id');
        if (! is_string($id) || $id === '') {
            throw new DubbingFailed('The worker did not return a job id. Check the worker log: docker compose logs worker');
        }

        return $id;
    }

    /**
     * Poll GET /jobs/{id} every POLL_SECONDS and copy the progress into the row until the job stops.
     *
     * Returns the worker's status once it is done, cancelled or waits for review. Throws DubbingFailed
     * with the worker's reason if it failed.
     *
     * @return array<string, mixed>
     */
    private function follow(VideoJob $job, string $workerId): array
    {
        while (true) {
            $response = $this->worker()
                ->retry(3, 2000, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->get("/jobs/{$workerId}");
            $status = $this->check($response, 'report the progress')->json();

            if (in_array($status['status'] ?? null, ['done', 'review', 'cancelled'], true)) {
                return $status;
            }
            if (($status['status'] ?? null) === 'failed') {
                throw new DubbingFailed($status['error'] ?? 'The worker could not process this video.');
            }
            if (! in_array($status['status'] ?? null, ['queued', 'processing'], true)) {
                throw new DubbingFailed('The worker sent a status this site does not understand. Check the worker log.');
            }

            $job->update([
                'status' => $status['status'] === 'queued' ? VideoJob::QUEUED : VideoJob::PROCESSING,
                'progress' => min(99, max(0, (int) ($status['progress'] ?? 0))), // 100 only once the files are here
                'message' => $status['message'] ?? null,
                'step_done' => $status['done'] ?? null,
                'step_total' => $status['total'] ?? null,
            ]);
            Sleep::sleep(self::POLL_SECONDS);
        }
    }

    /**
     * Save one result ("srt" or "video") of the worker job at `$path` on the local disk and return the path.
     */
    private function download(string $workerId, string $kind, string $path): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));
        $response = $this->worker()->timeout(self::TRANSFER_SECONDS)
            ->sink($disk->path($path))
            ->get("/jobs/{$workerId}/download/{$kind}");
        if (! $response->successful()) {
            $disk->delete($path); // the sink holds the error body, not the file
        }
        $this->check($response, $kind === 'srt' ? 'send the subtitles' : 'send the dubbed video');

        return $path;
    }

    /**
     * Return the response if it succeeded; otherwise log it and throw DubbingFailed with a clear reason.
     */
    private function check(Response $response, string $action): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $detail = $response->json('detail');
        Log::warning(sprintf('Dubbing worker could not %s for video job %d: HTTP %d %s',
            $action, $this->videoJob->id, $response->status(), mb_substr($response->body(), 0, 500)));

        throw new DubbingFailed(match (true) {
            $response->status() === 401 => Worker::WRONG_TOKEN,
            $response->status() === 404 && $action === 'report the progress' => 'The worker no longer has this job. Please upload the video again.',
            is_string($detail) && $detail !== '' => $detail,
            default => "The worker could not {$action} (HTTP {$response->status()}). Check the worker log: docker compose logs worker",
        });
    }

    /**
     * An HTTP client for the worker with its base URL and the x-token header.
     */
    private function worker(): PendingRequest
    {
        return Worker::client();
    }
}
