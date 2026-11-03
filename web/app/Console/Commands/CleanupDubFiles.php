<?php

namespace App\Console\Commands;

use App\Models\VideoJob;
use App\Support\Worker;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes old videos: their rows, uploads and results here, and the worker's job folders.
 *
 * Scheduled daily in routes/console.php; the scheduler service runs it.
 */
class CleanupDubFiles extends Command
{
    /** @var string */
    protected $signature = 'dub:cleanup {--days=7 : Delete videos older than this many days}';

    /** @var string */
    protected $description = 'Delete uploads, results and worker job folders older than 7 days';

    /**
     * Clean this site first, then ask the worker to clean its own folders.
     */
    public function handle(): int
    {
        $days = (float) $this->option('days');
        if ($days <= 0) {
            $this->error('--days must be more than 0.');

            return self::INVALID;
        }
        $cutoff = now()->subSeconds((int) round($days * 86400));

        $jobs = $this->deleteOldJobs($cutoff);
        [$uploads, $results] = $this->deleteUnusedFiles($cutoff);
        $this->info("Deleted {$jobs} videos older than {$days} days, with their uploads and results.");
        if ($uploads + $results > 0) {
            $this->info("Deleted {$uploads} uploads and {$results} result folders that no video uses.");
        }

        $error = $this->cleanWorker($days);
        if ($error !== null) {
            $this->error($error);
            Log::warning("dub:cleanup could not clean the worker: {$error}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Delete every video created before the cutoff, with its upload and results. Returns how many.
     */
    private function deleteOldJobs(CarbonInterface $cutoff): int
    {
        $count = 0;
        VideoJob::where('created_at', '<', $cutoff)->chunkById(100, function ($jobs) use (&$count) {
            foreach ($jobs as $job) {
                $job->deleteFiles();
                $job->delete();
                $count++;
            }
        });

        return $count;
    }

    /**
     * Delete uploads no row points to (older than the cutoff) and result folders of rows that are gone.
     *
     * @return array{int, int} the number of uploads and of result folders deleted
     */
    private function deleteUnusedFiles(CarbonInterface $cutoff): array
    {
        $disk = Storage::disk('local');

        $used = VideoJob::pluck('path')->flip();
        $uploads = 0;
        foreach ($disk->files('uploads') as $file) {
            if (! $used->has($file) && $disk->lastModified($file) < $cutoff->getTimestamp()) {
                $disk->delete($file);
                $uploads++;
            }
        }

        $ids = VideoJob::pluck('id')->flip();
        $results = 0;
        foreach ($disk->directories('results') as $folder) {
            if (! $ids->has((int) basename($folder))) {
                $disk->deleteDirectory($folder);
                $results++;
            }
        }

        return [$uploads, $results];
    }

    /**
     * Ask the worker to delete its finished job folders older than `$days`. Returns an error or null.
     */
    private function cleanWorker(float $days): ?string
    {
        try {
            $response = Worker::client()->timeout(120)->asForm()->post('/cleanup', ['days' => $days]);
        } catch (ConnectionException) {
            return Worker::UNREACHABLE.'. Its job folders were not deleted; they will be on the next run.';
        }

        if ($response->successful()) {
            $this->info("The worker deleted {$response->json('deleted', 0)} job folders.");

            return null;
        }

        return match ($response->status()) {
            401 => Worker::WRONG_TOKEN,
            404 => 'The worker has no /cleanup route yet. Restart it to load the new code: docker compose restart worker',
            default => "The worker could not clean up (HTTP {$response->status()}): ".($response->json('detail') ?? $response->body()),
        };
    }
}
