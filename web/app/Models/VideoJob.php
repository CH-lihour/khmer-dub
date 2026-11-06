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
 * @property string|null $language
 * @property string|null $worker_job_id
 * @property string|null $srt_path
 * @property string|null $video_path
 * @property string|null $error
 */
class VideoJob extends Model
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    /** Stopped after translation until a person has checked the Khmer. */
    public const REVIEW = 'review';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'original_name', 'path', 'gender', 'dub', 'review', 'original_volume', 'status', 'progress',
        'message', 'language', 'worker_job_id', 'srt_path', 'video_path', 'error',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'gender' => 'female',
        'dub' => true,
        'review' => false,
        'original_volume' => 0,
        'status' => self::QUEUED,
        'progress' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'dub' => 'boolean', 'review' => 'boolean', 'original_volume' => 'float', 'progress' => 'integer'];
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
        return in_array($this->status, [self::DONE, self::FAILED], true);
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
