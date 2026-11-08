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

    /**
     * Whisper codes of the languages the worker can translate to Khmer, and Khmer itself:
     * WHISPER_TO_NLLB in worker/stages/translate.py. The worker refuses any other code.
     */
    public const SPOKEN_LANGUAGES = [
        'af', 'am', 'ar', 'as', 'az', 'ba', 'be', 'bg', 'bn', 'bo', 'bs', 'ca', 'cs', 'cy', 'da', 'de', 'el', 'en', 'es',
        'et', 'eu', 'fa', 'fi', 'fo', 'fr', 'gl', 'gu', 'ha', 'he', 'hi', 'hr', 'ht', 'hu', 'hy', 'id', 'is', 'it', 'ja',
        'jw', 'ka', 'kk', 'km', 'kn', 'ko', 'lb', 'ln', 'lo', 'lt', 'lv', 'mg', 'mi', 'mk', 'ml', 'mn', 'mr', 'ms', 'mt',
        'my', 'ne', 'nl', 'nn', 'no', 'oc', 'pa', 'pl', 'ps', 'pt', 'ro', 'ru', 'sa', 'sd', 'si', 'sk', 'sl', 'sn', 'so',
        'sq', 'sr', 'su', 'sv', 'sw', 'ta', 'te', 'tg', 'th', 'tk', 'tl', 'tr', 'tt', 'uk', 'ur', 'uz', 'vi', 'yi', 'yo',
        'yue', 'zh',
    ];

    /** Whisper codes that ICU knows by another code, for their names. */
    private const ICU_CODES = ['jw' => 'jv'];

    /** Stopped after translation until a person has checked the Khmer. */
    public const REVIEW = 'review';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'original_name', 'path', 'gender', 'dub', 'review', 'original_volume', 'spoken_language', 'separate', 'status', 'progress',
        'message', 'step_done', 'step_total', 'language', 'worker_job_id', 'srt_path', 'video_path', 'error', 'cancel_requested',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'gender' => 'female',
        'dub' => true,
        'review' => false,
        'original_volume' => 0,
        'separate' => false,
        'status' => self::QUEUED,
        'progress' => 0,
        'cancel_requested' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'dub' => 'boolean', 'review' => 'boolean', 'original_volume' => 'float', 'separate' => 'boolean', 'progress' => 'integer', 'step_done' => 'integer', 'step_total' => 'integer', 'cancel_requested' => 'boolean'];
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
     * For a waiting job, how many videos (anyone's) will be worked on before it; null otherwise.
     *
     * The queue and the worker take one video at a time, in the order they were queued. A waiting
     * job is not touched until it starts, so its updated_at is when it was queued.
     */
    public function aheadInQueue(): ?int
    {
        if ($this->status !== self::QUEUED) {
            return null;
        }

        return self::whereKeyNot($this->id)
            ->where(fn ($ahead) => $ahead->where('status', self::PROCESSING)
                ->orWhere(fn ($queued) => $queued->where('status', self::QUEUED)
                    ->where(fn ($earlier) => $earlier->where('updated_at', '<', $this->updated_at)
                        ->orWhere(fn ($tie) => $tie->where('updated_at', $this->updated_at)->where('id', '<', $this->id)))))
            ->count();
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
        return $this->language === null ? null : self::nameOf($this->language);
    }

    /**
     * A Whisper language code by name, in the page's language; the code itself if ICU has no name.
     */
    public static function nameOf(string $code): string
    {
        $name = Locale::getDisplayLanguage(self::ICU_CODES[$code] ?? $code, app()->getLocale());

        return $name !== '' && $name !== $code ? $name : $code;
    }

    /**
     * The languages a person can say a video is spoken in, code => name, sorted by name in the
     * page's language.
     *
     * @return array<string, string>
     */
    public static function spokenLanguages(): array
    {
        $names = array_combine(self::SPOKEN_LANGUAGES, array_map(self::nameOf(...), self::SPOKEN_LANGUAGES));
        (new \Collator(app()->getLocale()))->asort($names);

        return $names;
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
