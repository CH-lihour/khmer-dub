<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideoJob;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dubbing page: upload a video, follow its progress, and download the Khmer results.
 */
class VideoJobController extends Controller
{
    /** Number of past jobs listed under the form. */
    private const HISTORY = 20;

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
            'gender' => ['sometimes', 'in:male,female'],
            'dub' => ['sometimes', 'boolean'],
            'review' => ['sometimes', 'boolean'],
        ], [
            'video.required' => __('Choose a video file.'),
            'video.uploaded' => __('The video did not upload completely. It may be larger than 500 MB.'),
            'video.mimes' => __('This file type is not supported. Upload an MP4, MOV, MKV or WebM video.'),
            'video.max' => __('The video is larger than 500 MB.'),
            'gender.in' => __('Choose the female or the male voice.'),
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
            'message' => 'Waiting in the queue',
        ]);
        ProcessVideoJob::dispatch($job);

        if ($request->expectsJson()) {
            return response()->json(['id' => $job->id, 'status_url' => route('dub.status', $job)], 201);
        }

        return redirect()->route('dub.index', ['job' => $job->id]);
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
     * Send a finished result: kind "srt" (Khmer subtitles) or "video" (Khmer dubbed MP4).
     * Only for the user who uploaded it.
     */
    public function download(Request $request, VideoJob $job, string $kind): StreamedResponse
    {
        $this->authorizeOwner($request, $job);
        $path = $kind === 'srt' ? $job->srt_path : $job->video_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404, 'This file is not ready or no longer exists.');

        return Storage::disk('local')->download($path, $job->downloadName($kind));
    }

    /**
     * Show the Khmer lines of a job waiting for review, next to the original text.
     */
    public function review(Request $request, VideoJob $job): View|RedirectResponse
    {
        $this->authorizeOwner($request, $job);
        if ($job->status !== VideoJob::REVIEW) {
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
     */
    public function saveReview(Request $request, VideoJob $job): RedirectResponse
    {
        $this->authorizeOwner($request, $job);
        if ($job->status !== VideoJob::REVIEW) {
            return redirect()->route('dub.index', ['job' => $job->id]);
        }

        $data = $request->validate([
            'km' => ['required', 'array'],
            'km.*' => ['nullable', 'string', 'max:2000'],
        ], ['km.*.max' => __('A line can have at most 2000 characters.')]);
        $lines = collect($data['km'])->map(fn (?string $text, int|string $id) => ['id' => (int) $id, 'km' => (string) $text]);

        try {
            $response = Worker::client()->put("/jobs/{$job->worker_job_id}/segments", ['segments' => $lines->values()->all()]);
        } catch (ConnectionException) {
            return back()->withInput()->withErrors(['review' => Worker::UNREACHABLE]);
        }
        if (! $response->successful()) {
            return back()->withInput()->withErrors(['review' => $this->workerError($response)]);
        }

        $job->update(['status' => VideoJob::QUEUED, 'message' => 'Waiting in the queue', 'error' => null]);
        ProcessVideoJob::dispatch($job);

        return redirect()->route('dub.index', ['job' => $job->id]);
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
        return [
            'id' => $job->id,
            'name' => $job->original_name,
            'status' => $job->status,
            'progress' => $job->progress,
            'message' => $job->message ? __($job->message) : null, // stored in English
            'error' => $job->error ? __($job->error) : null, // known messages have Khmer
            'language' => $job->language,
            'language_name' => $job->languageName(),
            'dub' => $job->dub,
            'status_url' => route('dub.status', $job),
            'review_url' => $job->status === VideoJob::REVIEW ? route('dub.review', $job) : null,
            'srt_url' => $job->srt_path ? route('dub.download', [$job, 'srt']) : null,
            'video_url' => $job->video_path ? route('dub.download', [$job, 'video']) : null,
        ];
    }
}
