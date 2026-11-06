<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\VideoJob;
use App\Support\Worker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dubbing page: upload a video, follow its progress, and download the Khmer results.
 */
class VideoJobController extends Controller
{
    /** Number of past jobs listed under the form. */
    private const HISTORY = 20;

    /** Seconds allowed for sending a video to the worker again. */
    private const TRANSFER_SECONDS = 600;

    /** The choices on the upload form. */
    private const OPTION_RULES = [
        'gender' => ['sometimes', 'in:male,female'],
        'dub' => ['sometimes', 'boolean'],
        'review' => ['sometimes', 'boolean'],
        'original_volume' => ['sometimes', 'numeric', 'between:0,1'],
    ];

    /**
     * Show the upload form, the job being followed and the user's recent jobs.
     *
     * The followed job is ?job=<id> if it is the user's, or else their newest job that has not finished.
     */
    public function index(Request $request): View
    {
        $jobs = $request->user()->videoJobs()->latest('id')->limit(self::HISTORY)->get();
        $current = $request->filled('job')
            ? $request->user()->videoJobs()->find($request->integer('job'))
            : $jobs->first(fn (VideoJob $job) => ! $job->isFinished());

        return view('dub.index', [
            'jobs' => $jobs,
            'current' => $current ? $this->statusData($current) : null,
            'options' => $request->user()->dubOptions(),
        ]);
    }

    /**
     * Validate and save the upload under a random name, then queue it for the worker.
     *
     * Answers JSON {id, status_url} to fetch requests, and redirects to the page otherwise.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'video' => ['required', 'file', 'mimes:mp4,mov,mkv,webm', 'max:512000'],
            ...self::OPTION_RULES,
        ], [
            'video.required' => __('Choose a video file.'),
            'video.uploaded' => __('The video did not upload completely. It may be larger than 500 MB.'),
            'video.mimes' => __('This file type is not supported. Upload an MP4, MOV, MKV or WebM video.'),
            'video.max' => __('The video is larger than 500 MB.'),
            'gender.in' => __('Choose the female or the male voice.'),
            'original_volume.*' => __('Choose an original sound level from 0 to 100%.'),
        ]);

        $file = $request->file('video');
        $path = $file->store('uploads', 'local'); // a random name with the real extension
        if ($path === false) {
            throw ValidationException::withMessages(['video' => 'The server could not save the video. Check its free disk space.']);
        }

        $job = $request->user()->videoJobs()->create([
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'path' => $path,
            'gender' => $data['gender'] ?? 'female',
            'dub' => $request->boolean('dub'),
            'review' => $request->boolean('review'),
            'original_volume' => $data['original_volume'] ?? 0,
            'message' => 'Waiting in the queue',
        ]);
        ProcessVideoJob::dispatch($job);
        $this->rememberOptions($request);

        if ($request->expectsJson()) {
            return response()->json(['id' => $job->id, 'status_url' => route('dub.status', $job)], 201);
        }

        return redirect()->route('dub.index', ['job' => $job->id]);
    }

    /**
     * Keep the form's choices as soon as one changes, so they survive a refresh. The page sends this.
     */
    public function saveOptions(Request $request): HttpResponse
    {
        $request->validate(self::OPTION_RULES);
        $this->rememberOptions($request);

        return response()->noContent();
    }

    /**
     * The job's progress as JSON, polled by the page every 3 seconds. Only for the user who uploaded it.
     */
    public function status(Request $request, VideoJob $job): JsonResponse
    {
        $this->authorizeOwner($request, $job);

        return response()->json($this->statusData($job));
    }

    /**
     * Stop a job that is waiting or running, and answer its status as JSON.
     *
     * A video not sent to the worker yet ends here. Otherwise the worker is asked to stop it: a job
     * in line there stops at once, and a running one at its next step, which ProcessVideoJob then
     * sees. A video still on its way is cancelled by ProcessVideoJob when it arrives. Only for the
     * user who uploaded it.
     */
    public function cancel(Request $request, VideoJob $job): JsonResponse
    {
        $this->authorizeOwner($request, $job);
        if ($job->isRunning() && ! $job->cancelUnsent()) {
            $job->update(['cancel_requested' => true]);
            $refused = $job->refresh()->worker_job_id !== null ? $this->cancelOnWorker($job) : null;
            if ($refused !== null) {
                return response()->json(['message' => __($refused)], 503);
            }
        }

        return response()->json($this->statusData($job->refresh()));
    }

    /**
     * Delete a stopped video for good: its worker job, upload, results and row. Nothing is deleted
     * here unless the worker has let go of it too, so no copy stays behind. Only for the user who
     * uploaded it.
     */
    public function destroy(Request $request, VideoJob $job): HttpResponse
    {
        $this->authorizeOwner($request, $job);
        if ($job->isRunning()) {
            return response()->json(['message' => __('Cancel this video before deleting it.')], 409);
        }

        $refused = $job->worker_job_id !== null ? $this->deleteOnWorker($job) : null;
        if ($refused !== null) {
            return response()->json(['message' => __($refused)], 503);
        }

        $job->deleteFiles();
        $job->delete();

        return response()->noContent();
    }

    /**
     * Send a finished result: kind "srt" (Khmer subtitles) or "video" (Khmer dubbed MP4).
     * Only for the user who uploaded it.
     */
    public function download(Request $request, VideoJob $job, string $kind): StreamedResponse
    {
        $this->authorizeOwner($request, $job);

        return Storage::disk('local')->download($this->resultPath($job, $kind === 'srt'), $job->downloadName($kind));
    }

    /**
     * Send a finished result for the page's player: kind "video" (the MP4, with range requests so
     * the player can seek) or "vtt" (the subtitles as WebVTT). Only for the user who uploaded it.
     */
    public function preview(Request $request, VideoJob $job, string $kind): BinaryFileResponse|HttpResponse
    {
        $this->authorizeOwner($request, $job);
        $path = $this->resultPath($job, $kind === 'vtt');
        $disk = Storage::disk('local');

        return $kind === 'vtt'
            ? response(VideoJob::srtToVtt($disk->get($path)), 200, ['Content-Type' => 'text/vtt; charset=UTF-8'])
            : response()->file($disk->path($path), ['Content-Type' => 'video/mp4']);
    }

    /**
     * Show the Khmer lines of a job waiting for review or a finished dubbed video, next to the original text.
     */
    public function review(Request $request, VideoJob $job): View|RedirectResponse
    {
        $this->authorizeOwner($request, $job);
        if (! $job->canEdit()) {
            return redirect()->route('dub.index', ['job' => $job->id]);
        }

        try {
            $response = Worker::client()->get("/jobs/{$job->worker_job_id}/segments");
        } catch (ConnectionException) {
            return redirect()->route('dub.index', ['job' => $job->id])->withErrors(['review' => Worker::UNREACHABLE]);
        }
        if (! $response->successful()) {
            return redirect()->route('dub.index', ['job' => $job->id])->withErrors(['review' => $this->workerError($response)]);
        }

        return view('dub.review', ['job' => $job, 'segments' => $response->json('segments')]);
    }

    /**
     * Send the reviewed Khmer to the worker and queue the job again to follow the rest of it.
     *
     * The worker no longer has the video of a finished job, so it gets it again first. The old
     * results stay in place until the new ones are here.
     */
    public function saveReview(Request $request, VideoJob $job): RedirectResponse
    {
        $this->authorizeOwner($request, $job);
        if (! $job->canEdit()) {
            return redirect()->route('dub.index', ['job' => $job->id]);
        }

        $data = $request->validate([
            'km' => ['required', 'array'],
            'km.*' => ['nullable', 'string', 'max:2000'],
            'gender' => self::OPTION_RULES['gender'],
            'original_volume' => self::OPTION_RULES['original_volume'],
        ], [
            'km.*.max' => __('A line can have at most 2000 characters.'),
            'gender.in' => __('Choose the female or the male voice.'),
            'original_volume.*' => __('Choose an original sound level from 0 to 100%.'),
        ]);
        $lines = collect($data['km'])->map(fn (?string $text, int|string $id) => ['id' => (int) $id, 'km' => (string) $text]);
        $choices = ['gender' => $data['gender'] ?? $job->gender, 'original_volume' => (float) ($data['original_volume'] ?? $job->original_volume)];

        try {
            $response = $job->status === VideoJob::DONE ? $this->reopen($job) : null;
            if ($response === null || $response->successful()) {
                $response = Worker::client()->put("/jobs/{$job->worker_job_id}/segments", ['segments' => $lines->values()->all(), ...$choices]);
            }
        } catch (ConnectionException) {
            return back()->withInput()->withErrors(['review' => Worker::UNREACHABLE]);
        }
        if (! $response->successful()) {
            return back()->withInput()->withErrors(['review' => $this->workerError($response)]);
        }

        $job->update(['status' => VideoJob::QUEUED, 'progress' => 50, 'message' => 'Waiting in the queue', 'error' => null, 'cancel_requested' => false, ...$choices]);
        ProcessVideoJob::dispatch($job);

        return redirect()->route('dub.index', ['job' => $job->id]);
    }

    /**
     * Send the original video to the worker again, which puts its finished job back in review.
     */
    private function reopen(VideoJob $job): Response
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($job->path)) {
            throw ValidationException::withMessages(['review' => __('The uploaded video is missing on the server. Please upload it again.')]);
        }

        $stream = $disk->readStream($job->path);
        try {
            return Worker::client()->timeout(self::TRANSFER_SECONDS)
                ->attach('file', $stream, basename($job->path))
                ->post("/jobs/{$job->worker_job_id}/reopen");
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Ask the worker to stop a job. Returns why it could not, or null. A job it finished just now
     * cannot be stopped any more, so it goes on as normal; a job it no longer has is over.
     */
    private function cancelOnWorker(VideoJob $job): ?string
    {
        try {
            $response = Worker::client()->post("/jobs/{$job->worker_job_id}/cancel");
        } catch (ConnectionException) {
            $job->update(['cancel_requested' => false]);

            return Worker::UNREACHABLE;
        }

        $stopped = $response->status() === 404 ? VideoJob::CANCELLED : $response->json('status');
        if (in_array($stopped, [VideoJob::CANCELLED, VideoJob::REVIEW], true)) {
            $job->markStopped($stopped);
        } elseif (! $response->successful()) {
            $job->update(['cancel_requested' => false]);

            return $response->status() === 409 ? null : $this->workerError($response);
        }

        return null;
    }

    /**
     * Ask the worker to delete a job. Returns why it could not, or null. A job it no longer has is
     * already gone, like one the weekly cleanup got to first.
     */
    private function deleteOnWorker(VideoJob $job): ?string
    {
        try {
            $response = Worker::client()->delete("/jobs/{$job->worker_job_id}");
        } catch (ConnectionException) {
            return Worker::UNREACHABLE;
        }

        return $response->successful() || $response->status() === 404 ? null : $this->workerError($response);
    }

    /**
     * Keep the form's choices for this user. The form sends no original sound level while dubbing
     * is off, so the level they chose before stays.
     */
    private function rememberOptions(Request $request): void
    {
        $user = $request->user();
        $user->forceFill(['dub_options' => [
            'gender' => $request->input('gender', User::DUB_DEFAULTS['gender']),
            'dub' => $request->boolean('dub'),
            'review' => $request->boolean('review'),
            'original_volume' => (float) $request->input('original_volume', $user->dubOptions()['original_volume']),
        ]])->save();
    }

    /**
     * The stored path of a job's subtitles or dubbed video. Answers 404 if it is not there.
     */
    private function resultPath(VideoJob $job, bool $subtitles): string
    {
        $path = $subtitles ? $job->srt_path : $job->video_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404, 'This file is not ready or no longer exists.');

        return $path;
    }

    /**
     * A clear sentence for a failed worker call.
     */
    private function workerError(Response $response): string
    {
        $detail = $response->json('detail');

        return match (true) {
            $response->status() === 401 => Worker::WRONG_TOKEN,
            $response->status() === 404 => 'The worker no longer has this job. Please upload the video again.',
            is_string($detail) && $detail !== '' => $detail,
            default => "The worker answered HTTP {$response->status()}. Check the worker log: docker compose logs worker",
        };
    }

    /**
     * Answer 403 unless the signed-in user uploaded the job (job->user_id === auth()->id()).
     */
    private function authorizeOwner(Request $request, VideoJob $job): void
    {
        abort_unless($job->isOwnedBy($request->user()), 403, 'This video belongs to another account.');
    }

    /**
     * What the page needs to show a job.
     *
     * @return array<string, mixed>
     */
    private function statusData(VideoJob $job): array
    {
        $version = $job->updated_at?->timestamp; // a remade video gets a new address, so no browser shows the old one
        $cancelling = $job->isRunning() && $job->cancel_requested;

        return [
            'id' => $job->id,
            'name' => $job->original_name,
            'status' => $job->status,
            'progress' => $job->progress,
            'message' => $cancelling ? __('Cancelling') : ($job->message ? __($job->message) : null), // stored in English
            'step_done' => $cancelling ? null : $job->step_done,
            'step_total' => $cancelling ? null : $job->step_total,
            'error' => $job->error ? __($job->error) : null, // known messages have Khmer
            'language' => $job->language,
            'language_name' => $job->languageName(),
            'dub' => $job->dub,
            'status_url' => route('dub.status', $job),
            'cancel_url' => $job->isRunning() && ! $cancelling && $job->progress < 99 ? route('dub.cancel', $job) : null, // at 99 only the download is left
            'cancel_keeps_edits' => $job->language !== null, // known once the Khmer is ready, so past any review
            'delete_url' => $job->isRunning() ? null : route('dub.destroy', $job),
            'review_url' => $job->status === VideoJob::REVIEW ? route('dub.review', $job) : null,
            'edit_url' => $job->status === VideoJob::DONE && $job->canEdit() ? route('dub.review', $job) : null,
            'srt_url' => $job->srt_path ? route('dub.download', [$job, 'srt']) : null,
            'video_url' => $job->video_path ? route('dub.download', [$job, 'video']) : null,
            'preview_url' => $job->hasPlayableVideo() ? route('dub.preview', [$job, 'video', 'v' => $version]) : null,
            'subtitles_url' => $job->hasPlayableVideo() && $job->srt_path ? route('dub.preview', [$job, 'vtt', 'v' => $version]) : null,
        ];
    }
}
