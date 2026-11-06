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
            ->assertSee('name="original_volume"', false)->assertSee('Original sound')
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
            'original_volume' => '0.35',
        ], self::JSON);

        $job = VideoJob::sole();
        $response->assertCreated()->assertExactJson(['id' => $job->id, 'status_url' => route('dub.status', $job)]);
        $this->assertSame($this->user->id, $job->user_id);
        $this->assertSame('My Holiday.mp4', $job->original_name);
        $this->assertMatchesRegularExpression('#^uploads/[A-Za-z0-9]{40}\.mp4$#', $job->path);
        Storage::disk('local')->assertExists($job->path);
        $this->assertSame(['male', true, 'queued', 0, 'Waiting in the queue'], [$job->gender, $job->dub, $job->status, $job->progress, $job->message]);
        $this->assertSame(0.35, $job->original_volume);
        Queue::assertPushed(ProcessVideoJob::class, fn (ProcessVideoJob $pushed) => $pushed->videoJob->is($job));
    }

    public function test_a_new_user_gets_the_default_choices(): void
    {
        $this->get('/dub')->assertOk()
            ->assertSee('value="female" checked', false)->assertSee('name="dub" value="1" checked', false)
            ->assertSee('name="original_volume" min="0" max="1" step="0.05" value="0"', false)
            ->assertDontSee('name="review" value="1" checked', false);
    }

    public function test_the_form_remembers_the_choices_of_the_last_upload(): void
    {
        $this->post('/dub', [
            'video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4'),
            'gender' => 'male', 'dub' => '1', 'review' => '1', 'original_volume' => '0.35',
        ], self::JSON)->assertCreated();

        $this->assertSame(
            ['gender' => 'male', 'dub' => true, 'review' => true, 'original_volume' => 0.35],
            $this->user->fresh()->dubOptions(),
        );
        $this->get('/dub')->assertOk()
            ->assertSee('value="male" checked', false)->assertDontSee('value="female" checked', false)
            ->assertSee('name="dub" value="1" checked', false)->assertSee('name="review" value="1" checked', false)
            ->assertSee('step="0.05" value="0.35"', false)->assertSee('35%');
    }

    public function test_a_changed_choice_survives_a_refresh_without_an_upload(): void
    {
        $this->post(route('dub.options'), ['gender' => 'male', 'dub' => '1', 'review' => '1', 'original_volume' => '0.6'], self::JSON)
            ->assertNoContent();

        $this->assertSame(0, VideoJob::count());
        $this->get('/dub')->assertOk()
            ->assertSee('value="male" checked', false)->assertSee('name="review" value="1" checked', false)
            ->assertSee('step="0.05" value="0.6"', false)->assertSee('60%');
    }

    public function test_turning_dubbing_off_keeps_the_chosen_original_sound_level(): void
    {
        $this->user->forceFill(['dub_options' => [...User::DUB_DEFAULTS, 'original_volume' => 0.4]])->save();

        $this->post(route('dub.options'), ['gender' => 'female'], self::JSON)->assertNoContent(); // the form sends no level then

        $options = $this->user->fresh()->dubOptions();
        $this->assertSame([false, false, 0.4], [$options['dub'], $options['review'], $options['original_volume']]);
    }

    public function test_bad_choices_are_not_saved(): void
    {
        $this->post(route('dub.options'), ['gender' => 'robot'], self::JSON)->assertUnprocessable();
        $this->post(route('dub.options'), ['original_volume' => '1.5'], self::JSON)->assertUnprocessable();

        $this->assertNull($this->user->fresh()->dub_options);
    }

    public function test_guests_cannot_save_choices(): void
    {
        auth()->logout();

        $this->post(route('dub.options'), ['gender' => 'male'])->assertRedirect(route('login'));
    }

    public function test_a_subtitles_only_upload_keeps_the_chosen_original_sound_level(): void
    {
        $this->user->forceFill(['dub_options' => [...User::DUB_DEFAULTS, 'original_volume' => 0.4]])->save();

        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4')], self::JSON)->assertCreated();

        $options = $this->user->fresh()->dubOptions();
        $this->assertSame([false, 0.4], [$options['dub'], $options['original_volume']]);
        $this->assertSame(0.0, VideoJob::sole()->original_volume);
        $this->get('/dub')->assertOk()->assertDontSee('name="dub" value="1" checked', false)
            ->assertSee('class="level off"', false)->assertSee('--fill: 40%', false);
    }

    public function test_choices_belong_to_each_user_and_a_refused_upload_changes_nothing(): void
    {
        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4'), 'gender' => 'male'], self::JSON)->assertCreated();
        $this->post('/dub', ['gender' => 'female'], self::JSON)->assertUnprocessable();
        $this->assertSame('male', $this->user->fresh()->dubOptions()['gender']);

        $this->actingAs(User::factory()->create());
        $this->get('/dub')->assertOk()->assertSee('value="female" checked', false);
    }

    public function test_unchecked_dub_means_subtitles_only_and_voice_defaults_to_female(): void
    {
        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.webm', 10, 'video/webm')], self::JSON)->assertCreated();

        $job = VideoJob::sole();
        $this->assertSame(['female', false, 0.0], [$job->gender, $job->dub, $job->original_volume]);
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
            'original sound too loud' => [['video' => UploadedFile::fake()->create('a.mp4', 1, 'video/mp4'), 'original_volume' => '1.5'], 'original_volume', '0 to 100%'],
            'original sound not a number' => [['video' => UploadedFile::fake()->create('a.mp4', 1, 'video/mp4'), 'original_volume' => 'loud'], 'original_volume', '0 to 100%'],
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
            'edit_url' => null, 'preview_url' => null, 'subtitles_url' => null,
        ]);
    }

    public function test_status_of_a_finished_job_has_the_language_and_download_links(): void
    {
        $job = $this->makeJob(['status' => 'done', 'progress' => 100, 'language' => 'en', 'srt_path' => 'results/1/khmer.srt', 'video_path' => 'results/1/dubbed.mp4']);

        $this->getJson(route('dub.status', $job))->assertOk()->assertJson([
            'status' => 'done', 'language' => 'en', 'language_name' => 'English',
            'srt_url' => route('dub.download', [$job, 'srt']), 'video_url' => route('dub.download', [$job, 'video']),
            'preview_url' => route('dub.preview', [$job, 'video', 'v' => $job->updated_at->timestamp]),
            'subtitles_url' => route('dub.preview', [$job, 'vtt', 'v' => $job->updated_at->timestamp]),
        ]);
    }

    public function test_a_finished_dubbed_video_offers_editing(): void
    {
        $job = $this->makeJob(['status' => 'done', 'worker_job_id' => 'w1', 'video_path' => 'results/1/dubbed.mp4']);

        $this->getJson(route('dub.status', $job))->assertOk()->assertJson(['edit_url' => route('dub.review', $job)]);
        $this->get(route('dub.index', ['job' => $job->id]))->assertOk()->assertSee('Edit and regenerate');
    }

    public function test_a_video_being_made_again_is_not_offered_for_playing_but_stays_for_download(): void
    {
        $job = $this->makeJob(['status' => 'processing', 'progress' => 60, 'worker_job_id' => 'w1', 'video_path' => 'results/1/dubbed.mp4']);

        $this->getJson(route('dub.status', $job))->assertOk()->assertJson([
            'preview_url' => null, 'edit_url' => null, 'video_url' => route('dub.download', [$job, 'video']),
        ]);
        $this->get('/dub')->assertOk()->assertDontSee('class="play" href="'.route('dub.index', ['job' => $job->id]).'"', false);
    }

    public function test_a_subtitles_only_job_has_nothing_to_preview(): void
    {
        $job = $this->makeJob(['status' => 'done', 'dub' => false, 'srt_path' => 'results/1/khmer.srt']);

        $this->getJson(route('dub.status', $job))->assertOk()->assertJson(['preview_url' => null, 'subtitles_url' => null]);
    }

    public function test_old_dubbed_videos_can_be_played_from_the_history(): void
    {
        $dubbed = $this->makeJob(['status' => 'done', 'video_path' => 'results/1/dubbed.mp4']);
        $subtitles = $this->makeJob(['status' => 'done', 'dub' => false, 'srt_path' => 'results/2/khmer.srt']);

        $this->get('/dub')->assertOk()
            ->assertSee('Play')
            ->assertSee('class="play" href="'.route('dub.index', ['job' => $dubbed->id]).'"', false)
            ->assertDontSee('class="play" href="'.route('dub.index', ['job' => $subtitles->id]).'"', false);
    }

    public function test_every_video_in_the_history_opens_without_leaving_the_page(): void
    {
        $job = $this->makeJob(['status' => 'failed']);

        $this->get('/dub')->assertOk()
            ->assertSee('<a href="'.route('dub.index', ['job' => $job->id]).'" data-status-url="'.route('dub.status', $job).'">', false);
    }

    public function test_the_player_can_seek_in_the_dubbed_video(): void
    {
        Storage::disk('local')->put('results/1/dubbed.mp4', 'fake mp4 bytes');
        $job = $this->makeJob(['status' => 'done', 'video_path' => 'results/1/dubbed.mp4']);

        $whole = $this->get(route('dub.preview', [$job, 'video']))->assertOk()->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringNotContainsString('attachment', (string) $whole->headers->get('Content-Disposition'));

        $this->get(route('dub.preview', [$job, 'video']), ['Range' => 'bytes=5-7'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 5-7/14')->assertHeader('Content-Length', '3');
    }

    public function test_the_player_gets_the_subtitles_as_webvtt(): void
    {
        Storage::disk('local')->put('results/1/khmer.srt', "1\n00:00:01,200 --> 00:00:04,800\nសួស្តី 12,500\n\n2\n01:02:03,000 --> 01:02:04,500\nលា\n");
        $job = $this->makeJob(['status' => 'done', 'srt_path' => 'results/1/khmer.srt']);

        $response = $this->get(route('dub.preview', [$job, 'vtt']))->assertOk()->assertHeader('Content-Type', 'text/vtt; charset=UTF-8');

        $this->assertSame(
            "WEBVTT\n\n1\n00:00:01.200 --> 00:00:04.800\nសួស្តី 12,500\n\n2\n01:02:03.000 --> 01:02:04.500\nលា\n",
            $response->getContent(),
        );
    }

    public function test_previews_are_only_for_the_owner_and_only_when_ready(): void
    {
        Storage::disk('local')->put('results/1/dubbed.mp4', 'fake mp4 bytes');
        $other = $this->makeJob(['video_path' => 'results/1/dubbed.mp4', 'srt_path' => 'results/1/khmer.srt'], User::factory()->create());
        $this->get(route('dub.preview', [$other, 'video']))->assertForbidden();
        $this->get(route('dub.preview', [$other, 'vtt']))->assertForbidden();

        $missing = $this->makeJob(['video_path' => 'results/2/dubbed.mp4']); // the row says so, but the file is gone
        $this->get(route('dub.preview', [$missing, 'video']))->assertNotFound();
        $this->get(route('dub.preview', [$missing, 'vtt']))->assertNotFound();
        $this->get("/dub/{$missing->id}/preview/audio")->assertNotFound();

        auth()->logout();
        $this->get(route('dub.preview', [$other, 'video']))->assertRedirect(route('login'));
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
