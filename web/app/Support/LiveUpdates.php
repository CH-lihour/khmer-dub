<?php

namespace App\Support;

use App\Events\VideoJobUpdated;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a job's progress to its owner's page through Reverb, when BROADCAST_CONNECTION=reverb.
 *
 * Live progress is a nicety: the page polls when it gets none. So a stopped Reverb never fails or
 * slows a video job: after one failed send, none is tried for a minute.
 */
class LiveUpdates
{
    /** How long to stop sending after a failure. */
    public const PAUSE_SECONDS = 60;

    private const DOWN = 'live-updates-down';

    /**
     * Whether pages should connect to Reverb at all.
     */
    public static function enabled(): bool
    {
        return config('broadcasting.default') === 'reverb';
    }

    /**
     * What the page needs to listen to `$user`'s channel (public/js/live.js), or null when live updates are off.
     *
     * @return array{key: string, scheme: string, port: int, auth: string, channel: string, csrf: string}|null
     */
    public static function pageConfig(User $user): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        return [
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'scheme' => (string) config('services.reverb_client.scheme'),
            'port' => (int) config('services.reverb_client.port'),
            'auth' => url('/broadcasting/auth'),
            'channel' => "private-users.{$user->id}",
            'csrf' => csrf_token(),
        ];
    }

    /**
     * The step messages a job can send, stored in English, in the page's language.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = ['Waiting in the queue', 'Starting', 'Sending the video to the worker', 'Extracting audio', 'Transcribing speech',
            'Finding the speakers', 'Translating to Khmer', 'Making the Khmer voice', 'Removing the original speech', 'Mixing and merging',
            'Downloading the results'];

        return array_combine($messages, array_map(fn (string $message) => __($message), $messages));
    }

    /**
     * Send the job's progress now, unless live updates are off or Reverb failed a moment ago.
     */
    public static function send(VideoJob $job): void
    {
        if (! self::enabled() || $job->user_id === null || Cache::has(self::DOWN)) {
            return;
        }
        try {
            event(new VideoJobUpdated($job));
        } catch (Throwable $exception) {
            Cache::put(self::DOWN, true, self::PAUSE_SECONDS);
            Log::info('Live progress is paused for a minute: Reverb did not answer. '.$exception->getMessage());
        }
    }
}
