<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideoJob;
use App\Models\VideoJob;
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
        ], [
            'video.required' => 'Choose a video file.',
            'video.uploaded' => 'The video did not upload completely. It may be larger than 500 MB.',
            'video.mimes' => 'This file type is not supported. Upload an MP4, MOV, MKV or WebM video.',
            'video.max' => 'The video is larger than 500 MB.',
            'gender.in' => 'Choose the female or the male voice.',
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
            'message' => $job->message,
            'error' => $job->error,
            'language' => $job->language,
            'language_name' => $job->languageName(),
            'dub' => $job->dub,
            'status_url' => route('dub.status', $job),
            'srt_url' => $job->srt_path ? route('dub.download', [$job, 'srt']) : null,
            'video_url' => $job->video_path ? route('dub.download', [$job, 'video']) : null,
        ];
    }
}
