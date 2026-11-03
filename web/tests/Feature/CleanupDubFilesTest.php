<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * dub:cleanup deletes old videos here and asks the worker to delete its old job folders.
 */
class CleanupDubFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dubber.url' => 'http://worker.test', 'services.dubber.token' => 'test-token']);
        Storage::fake('local');
    }

    /**
     * A finished video created `$daysOld` days ago, with its upload and results on disk.
     */
    private function video(float $daysOld): VideoJob
    {
        $job = User::factory()->create()->videoJobs()->create([
            'original_name' => 'talk.mp4', 'path' => 'uploads/'.uniqid().'.mp4', 'status' => 'done',
        ]);
        $job->forceFill(['created_at' => now()->subHours((int) ($daysOld * 24))])->save();
        Storage::disk('local')->put($job->path, 'video');
        Storage::disk('local')->put("{$job->resultsFolder()}/khmer.srt", 'subtitles');

        return $job;
    }

    /**
     * Put a file on the local disk with its last change `$daysOld` days ago.
     */
    private function oldFile(string $path, float $daysOld): void
    {
        Storage::disk('local')->put($path, 'x');
        $time = time() - (int) ($daysOld * 86400);
        touch(Storage::disk('local')->path($path), $time, $time);
    }

    public function test_deletes_videos_older_than_7_days_and_keeps_recent_ones(): void
    {
        Http::fake(['worker.test/cleanup' => Http::response(['deleted' => 2])]);
        $old = $this->video(8);
        $recent = $this->video(6);
        $this->oldFile('uploads/orphan.mp4', 8);   // its row is gone
        $this->oldFile('uploads/saving.mp4', 0);   // an upload being saved right now
        $this->oldFile('results/999/khmer.srt', 1); // results of a row that is gone

        $this->artisan('dub:cleanup')
            ->expectsOutputToContain('Deleted 1 videos older than 7 days')
            ->expectsOutputToContain('Deleted 1 uploads and 1 result folders that no video uses')
            ->expectsOutputToContain('The worker deleted 2 job folders.')
            ->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $disk = Storage::disk('local');
        $disk->assertMissing([$old->path, $old->resultsFolder(), 'uploads/orphan.mp4', 'results/999']);
        $disk->assertExists([$recent->path, "{$recent->resultsFolder()}/khmer.srt", 'uploads/saving.mp4']);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/cleanup'
            && $request->method() === 'POST' && $request->hasHeader('x-token', 'test-token') && $request['days'] == 7);
    }

    public function test_days_can_be_changed(): void
    {
        Http::fake(['worker.test/cleanup' => Http::response(['deleted' => 0])]);
        $video = $this->video(2);

        $this->artisan('dub:cleanup', ['--days' => 1])->assertSuccessful();

        $this->assertModelMissing($video);
        Http::assertSent(fn (Request $request) => $request['days'] == 1);
    }

    public function test_days_must_be_positive(): void
    {
        Http::fake();

        $this->artisan('dub:cleanup', ['--days' => 0])->expectsOutputToContain('--days must be more than 0')->assertExitCode(Command::INVALID);
        Http::assertNothingSent();
    }

    public function test_an_unreachable_worker_still_cleans_this_site_and_fails(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: worker'));
        $old = $this->video(8);

        $this->artisan('dub:cleanup')->expectsOutputToContain('could not be reached')->assertFailed();

        $this->assertModelMissing($old);
        Storage::disk('local')->assertMissing($old->path);
    }

    public function test_a_wrong_token_is_explained(): void
    {
        Http::fake(['worker.test/cleanup' => Http::response(['detail' => 'Wrong or missing x-token.'], 401)]);

        $this->artisan('dub:cleanup')->expectsOutputToContain('DUBBER_TOKEN in web/.env must equal WORKER_TOKEN')->assertFailed();
    }

    public function test_cleanup_is_scheduled_every_day(): void
    {
        // One output line, so one expectation: "0 0 * * *  php artisan dub:cleanup ... Next Due: ..."
        $this->artisan('schedule:list')->expectsOutputToContain('0 0 * * *  php artisan dub:cleanup')->assertSuccessful();
    }
}
