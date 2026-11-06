<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Locale;

/**
 * One uploaded video and the state of its dubbing job on the worker.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $original_name
 * @property string $path
 * @property string $gender
 * @property bool $dub
 * @property bool $review
 * @property float $original_volume
 * @property string $status
 * @property int $progress
 * @property string|null $message
 * @property int|null $step_done
 * @property int|null $step_total
 * @property string|null $language
 * @property string|null $worker_job_id
 * @property string|null $srt_path
 * @property string|null $video_path
 * @property string|null $error
 * @property bool $cancel_requested
 */
class VideoJob extends Model
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    /** Stopped after translation until a person has checked the Khmer. */
    public const REVIEW = 'review';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'original_name', 'path', 'gender', 'dub', 'review', 'original_volume', 'status', 'progress',
        'message', 'step_done', 'step_total', 'language', 'worker_job_id', 'srt_path', 'video_path', 'error', 'cancel_requested',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'gender' => 'female',
        'dub' => true,
        'review' => false,
        'original_volume' => 0,
        'status' => self::QUEUED,
        'progress' => 0,
        'cancel_requested' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'dub' => 'boolean', 'review' => 'boolean', 'original_volume' => 'float', 'progress' => 'integer', 'step_done' => 'integer', 'step_total' => 'integer', 'cancel_requested' => 'boolean'];
    }

    /**
     * Whether `$user` uploaded this video.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * The folder on the local disk that holds this job's results.
     */
    public function resultsFolder(): string
    {
        return "results/{$this->id}";
    }

    /**
     * Delete the upload and the results of this job from the local disk (not the row).
     */
    public function deleteFiles(): void
    {
        $disk = Storage::disk('local');
        $disk->delete($this->path);
        $disk->deleteDirectory($this->resultsFolder());
    }

    /**
     * The user who uploaded the video, once login exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the job has ended, successfully or not.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::DONE, self::FAILED, self::CANCELLED], true);
    }

    /**
     * Whether the job is waiting or being worked on, so it can be cancelled.
     */
    public function isRunning(): bool
    {
        return in_array($this->status, [self::QUEUED, self::PROCESSING], true);
    }

    /**
     * End a job that the queue has not started, in one step so the queue cannot start it meanwhile.
     * Returns false for any other job.
     */
    public function cancelUnsent(): bool
    {
        return self::whereKey($this->id)->where('status', self::QUEUED)->whereNull('worker_job_id')
            ->update(['status' => self::CANCELLED, 'message' => 'Cancelled']) === 1;
    }

    /**
     * Record that the worker has stopped this cancelled job: for good, or back at its review
     * (self::REVIEW) with the edits kept, where an older video stays playable.
     */
    public function markStopped(string $status): void
    {
        $outcome = $status === self::REVIEW
            ? ['status' => self::REVIEW, 'progress' => 50, 'message' => 'Waiting for your review']
            : ['status' => self::CANCELLED, 'message' => 'Cancelled'];
        $this->update([...$outcome, 'cancel_requested' => false, 'step_done' => null, 'step_total' => null]);
    }

    /**
     * Whether the Khmer can be edited now: a job waiting for review, or a finished dubbed video.
     */
    public function canEdit(): bool
    {
        return $this->status === self::REVIEW
            || ($this->status === self::DONE && $this->video_path !== null && $this->worker_job_id !== null);
    }

    /**
     * Whether there is a dubbed video to play. While one is being made again, the old one is not offered.
     */
    public function hasPlayableVideo(): bool
    {
        return $this->video_path !== null && in_array($this->status, [self::DONE, self::REVIEW], true);
    }

    /**
     * The detected language by name, in the page's language, or null before it is known.
     */
    public function languageName(): ?string
    {
        if ($this->language === null) {
            return null;
        }
        $name = Locale::getDisplayLanguage($this->language, app()->getLocale());

        return $name !== '' && $name !== $this->language ? $name : $this->language;
    }

    /**
     * Turn SRT subtitles into WebVTT, the only format the browser's video player reads.
     */
    public static function srtToVtt(string $srt): string
    {
        $times = '/^(\d{2}:\d{2}:\d{2}),(\d{3}) --> (\d{2}:\d{2}:\d{2}),(\d{3})$/m';

        return "WEBVTT\n\n".preg_replace($times, '$1.$2 --> $3.$4', $srt);
    }

    /**
     * The file name offered when downloading a result: "<video name>-khmer.srt" or "-khmer.mp4".
     */
    public function downloadName(string $kind): string
    {
        $base = Str::slug(pathinfo($this->original_name, PATHINFO_FILENAME)) ?: 'video';

        return $base.'-khmer.'.($kind === 'srt' ? 'srt' : 'mp4');
    }
}
