<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP client for the FastAPI dubbing worker, set up from config/services.php ("dubber").
 */
class Worker
{
    /** What to tell the user when the worker answers 401. */
    public const WRONG_TOKEN = 'The worker refused the token. DUBBER_TOKEN in web/.env must equal WORKER_TOKEN in worker/.env.';

    /** What to tell the user when the worker cannot be reached. */
    public const UNREACHABLE = 'The dubbing worker could not be reached. Check that it is running: docker compose ps worker';

    /**
     * Worker messages with a value inside, as a pattern and the lang/km.json key it fills.
     * Each pattern's named groups are the key's :placeholders. Fixed messages need no entry:
     * say() looks them up as they are.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/^The video is (?<minutes>[\d.]+) minutes long; the limit is (?<limit>[\d.]+) minutes\.$/'
            => 'The video is :minutes minutes long; the limit is :limit minutes.',
        "/^Translating from this language \\('(?<code>[^']*)'\\) to Khmer is not supported\\.$/"
            => "Translating from this language (':code') to Khmer is not supported.",
        "/^Could not load the speech model '(?<name>[^']*)'\\. The first run downloads it, so check the internet connection and the WHISPER_MODEL setting, then try again\\.$/"
            => "Could not load the speech model ':name'. The first run downloads it, so check the internet connection and the WHISPER_MODEL setting, then try again.",
        '/^The Khmer voice could not read this line: (?<line>.*)$/s'
            => 'The Khmer voice could not read this line: :line',
        "/^Unsupported file type '(?<type>[^']*)'\\. Upload an MP4, MOV, MKV or WebM video\\.$/"
            => "Unsupported file type ':type'. Upload an MP4, MOV, MKV or WebM video.",
        '/^These lines have no Khmer text: (?<lines>.*)\. Write them in Khmer, or empty them\.$/'
            => 'These lines have no Khmer text: :lines. Write them in Khmer, or empty them.',
        '/^The worker answered HTTP (?<status>\d+)\. Check the worker log: docker compose logs worker$/'
            => 'The worker answered HTTP :status. Check the worker log: docker compose logs worker',
        '/^The worker could not .+ \(HTTP (?<status>\d+)\)\. Check the worker log: docker compose logs worker$/'
            => 'The worker answered HTTP :status. Check the worker log: docker compose logs worker',
    ];

    /**
     * A message from the worker (or about it) in the page's language. Messages are stored and
     * logged in English; ones without Khmer stay in English.
     */
    public static function say(string $message): string
    {
        foreach (self::PATTERNS as $pattern => $key) {
            if (preg_match($pattern, $message, $match)) {
                return __($key, array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
            }
        }

        return __($message);
    }

    /**
     * A request to the worker with its base URL and the x-token header.
     */
    public static function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.dubber.url'), '/'))
            ->withHeaders(['x-token' => (string) config('services.dubber.token')])
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30);
    }
}
