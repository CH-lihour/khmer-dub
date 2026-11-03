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
