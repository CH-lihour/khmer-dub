<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * VideoJobController: the page, the upload, the status JSON, the downloads, and who may see them.
 */
class DubPageTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Create a row owned by `$owner` (default: the signed-in user).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeJob(array $attributes = [], ?User $owner = null): VideoJob
    {
        return ($owner ?? $this->user)->videoJobs()->create(['original_name' => 'talk.mp4', 'path' => 'uploads/abc.mp4', ...$attributes]);
    }

    public function test_home_redirects_to_the_dub_page(): void
    {
        $this->get('/')->assertRedirect('/dub');
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        auth()->logout();
        $job = $this->makeJob();

        $this->get('/dub')->assertRedirect(route('login'));
        $this->post('/dub', [])->assertRedirect(route('login'));
        $this->get(route('dub.download', [$job, 'srt']))->assertRedirect(route('login'));
        $this->getJson(route('dub.status', $job))->assertUnauthorized();
    }

    public function test_page_shows_the_form_and_the_users_history(): void
    {
        $job = $this->makeJob(['original_name' => 'old talk.mp4', 'status' => 'done', 'progress' => 100, 'srt_path' => 'results/1/khmer.srt']);

        $this->get('/dub')->assertOk()
            ->assertSee('Khmer Dub')->assertSee('Give your video a Khmer voice')->assertSee($this->user->name)
            ->assertSee('name="video"', false)->assertSee('name="gender"', false)->assertSee('name="dub"', false)
            ->assertSee('old talk.mp4')
            ->assertSee(route('dub.download', [$job, 'srt']));
    }

    public function test_page_follows_the_newest_unfinished_job(): void
    {
        $this->makeJob(['original_name' => 'finished.mp4', 'status' => 'done']);
        $running = $this->makeJob(['original_name' => 'running.mp4', 'status' => 'processing', 'progress' => 45, 'message' => 'Translating to Khmer']);

        $this->get('/dub')->assertOk()->assertViewHas('current', fn (array $current) => $current['id'] === $running->id);
    }

    public function test_another_users_videos_are_not_listed_or_followed(): void
    {
        $other = $this->makeJob(['original_name' => 'someone else.mp4', 'status' => 'processing'], User::factory()->create());

        $this->get('/dub')->assertOk()->assertDontSee('someone else.mp4')->assertViewHas('current', null);
        $this->get("/dub?job={$other->id}")->assertOk()->assertDontSee('someone else.mp4')->assertViewHas('current', null);
    }

    public function test_another_users_status_and_downloads_are_forbidden(): void
    {
        $other = $this->makeJob(['status' => 'done', 'srt_path' => 'results/1/khmer.srt', 'video_path' => 'results/1/dubbed.mp4'], User::factory()->create());
        Storage::disk('local')->put('results/1/khmer.srt', 'secret subtitles');
        Storage::disk('local')->put('results/1/dubbed.mp4', 'secret video');

        $this->getJson(route('dub.status', $other))->assertForbidden()->assertJson(['message' => 'This video belongs to another account.']);
        $this->get(route('dub.download', [$other, 'srt']))->assertForbidden();
        $this->get(route('dub.download', [$other, 'video']))->assertForbidden();
    }

    public function test_upload_is_saved_under_a_random_name_and_queued(): void
    {
        $response = $this->post('/dub', [
            'video' => UploadedFile::fake()->create('My Holiday.mp4', 1000, 'video/mp4'),
            'gender' => 'male',
            'dub' => '1',
        ], self::JSON);

        $job = VideoJob::sole();
        $response->assertCreated()->assertExactJson(['id' => $job->id, 'status_url' => route('dub.status', $job)]);
        $this->assertSame($this->user->id, $job->user_id);
        $this->assertSame('My Holiday.mp4', $job->original_name);
        $this->assertMatchesRegularExpression('#^uploads/[A-Za-z0-9]{40}\.mp4$#', $job->path);
        Storage::disk('local')->assertExists($job->path);
        $this->assertSame(['male', true, 'queued', 0, 'Waiting in the queue'], [$job->gender, $job->dub, $job->status, $job->progress, $job->message]);
        Queue::assertPushed(ProcessVideoJob::class, fn (ProcessVideoJob $pushed) => $pushed->videoJob->is($job));
    }

    public function test_unchecked_dub_means_subtitles_only_and_voice_defaults_to_female(): void
    {
        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.webm', 10, 'video/webm')], self::JSON)->assertCreated();

        $job = VideoJob::sole();
        $this->assertSame(['female', false], [$job->gender, $job->dub]);
        $this->assertStringEndsWith('.webm', $job->path);
    }

    public function test_form_post_without_javascript_redirects_to_the_job(): void
    {
        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.mkv', 10, 'video/x-matroska'), 'gender' => 'female', 'dub' => '1'])
            ->assertRedirect(route('dub.index', ['job' => VideoJob::sole()->id]));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function badUploads(): array
    {
        return [
            'no video' => [[], 'video', 'Choose a video file.'],
            'not a video' => [['video' => UploadedFile::fake()->create('notes.mp4', 1, 'text/plain')], 'video', 'This file type is not supported'],
            'too big' => [['video' => UploadedFile::fake()->create('big.mp4', 512001, 'video/mp4')], 'video', 'larger than 500 MB'],
            'unknown voice' => [['video' => UploadedFile::fake()->create('a.mp4', 1, 'video/mp4'), 'gender' => 'robot'], 'gender', 'female or the male voice'],
            'dub not boolean' => [['video' => UploadedFile::fake()->create('a.mp4', 1, 'video/mp4'), 'dub' => 'maybe'], 'dub', 'true or false'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('badUploads')]
    public function test_bad_uploads_are_refused(array $data, string $field, string $message): void
    {
        $response = $this->post('/dub', $data, self::JSON)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertStringContainsString($message, $response->json("errors.{$field}.0"));
        $this->assertSame(0, VideoJob::count());
        Queue::assertNothingPushed();
    }

    public function test_status_returns_the_progress_as_json(): void
    {
        $job = $this->makeJob(['status' => 'processing', 'progress' => 45, 'message' => 'Translating to Khmer']);

        $this->getJson(route('dub.status', $job))->assertOk()->assertExactJson([
            'id' => $job->id, 'name' => 'talk.mp4', 'status' => 'processing', 'progress' => 45,
            'message' => 'Translating to Khmer', 'error' => null, 'language' => null, 'language_name' => null,
            'dub' => true, 'status_url' => route('dub.status', $job), 'review_url' => null, 'srt_url' => null, 'video_url' => null,
        ]);
    }

    public function test_status_of_a_finished_job_has_the_language_and_download_links(): void
    {
        $job = $this->makeJob(['status' => 'done', 'progress' => 100, 'language' => 'en', 'srt_path' => 'results/1/khmer.srt', 'video_path' => 'results/1/dubbed.mp4']);

        $this->getJson(route('dub.status', $job))->assertOk()->assertJson([
            'status' => 'done', 'language' => 'en', 'language_name' => 'English',
            'srt_url' => route('dub.download', [$job, 'srt']), 'video_url' => route('dub.download', [$job, 'video']),
        ]);
    }

    public function test_downloads_send_the_results_with_a_readable_name(): void
    {
        $job = $this->makeJob(['original_name' => 'My Talk.mp4', 'status' => 'done', 'srt_path' => 'results/1/khmer.srt', 'video_path' => 'results/1/dubbed.mp4']);
        Storage::disk('local')->put('results/1/khmer.srt', "1\n00:00:01,000 --> 00:00:02,000\nសួស្តី\n");
        Storage::disk('local')->put('results/1/dubbed.mp4', 'fake mp4');

        $srt = $this->get(route('dub.download', [$job, 'srt']))->assertOk()->assertDownload('my-talk-khmer.srt');
        $this->assertStringContainsString('សួស្តី', $srt->streamedContent());
        $video = $this->get(route('dub.download', [$job, 'video']))->assertOk()->assertDownload('my-talk-khmer.mp4');
        $this->assertSame('fake mp4', $video->streamedContent());
    }

    public function test_khmer_file_names_get_a_safe_download_name(): void
    {
        $job = $this->makeJob(['original_name' => 'វីដេអូ.mp4', 'srt_path' => 'results/1/khmer.srt']);
        Storage::disk('local')->put('results/1/khmer.srt', 'x');

        $this->get(route('dub.download', [$job, 'srt']))->assertOk()->assertDownload('video-khmer.srt');
    }

    public function test_missing_results_are_404(): void
    {
        $job = $this->makeJob(['srt_path' => 'results/1/khmer.srt']); // the row says so, but the file is gone

        $this->get(route('dub.download', [$job, 'srt']))->assertNotFound();
        $this->get(route('dub.download', [$job, 'video']))->assertNotFound();
        $this->get("/dub/{$job->id}/download/audio")->assertNotFound();
        $this->get('/dub/999/status')->assertNotFound();
    }

    public function test_deleting_the_account_deletes_its_videos_and_files(): void
    {
        $job = $this->makeJob(['srt_path' => "results/{$this->user->id}/khmer.srt"]);
        Storage::disk('local')->put($job->path, 'video');
        Storage::disk('local')->put("{$job->resultsFolder()}/khmer.srt", 'subtitles');

        $this->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertModelMissing($job);
        Storage::disk('local')->assertMissing($job->path);
        Storage::disk('local')->assertMissing($job->resultsFolder());
    }
}
