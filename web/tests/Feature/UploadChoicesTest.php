<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The spoken language a person can choose, and taking the original speech out of the sound.
 */
class UploadChoicesTest extends TestCase
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

    private function upload(array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->post('/dub', ['video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4'), ...$fields], self::JSON);
    }

    public function test_the_form_lists_the_languages_by_name_with_detect_first(): void
    {
        $page = $this->get('/dub')->assertOk()
            ->assertSeeInOrder(['name="spoken_language"', '<option value="">Detect it</option>', 'value="af" >Afrikaans</option>', 'value="sq" >Albanian'], false)
            ->assertSee('value="jw" >Javanese</option>', false)
            ->assertSee('name="separate" value="1"', false);
        $this->assertSame(count(VideoJob::SPOKEN_LANGUAGES) + 1, substr_count($page->getContent(), '<option value="') - 7, 'the status filter has 7');
    }

    public function test_the_choices_are_saved_with_the_job(): void
    {
        $this->upload(['spoken_language' => 'fr', 'dub' => '1', 'separate' => '1', 'original_volume' => '1'])->assertCreated();

        $job = VideoJob::sole();
        $this->assertSame(['fr', true], [$job->spoken_language, $job->separate]);
        $this->assertTrue($this->user->fresh()->dubOptions()['separate'], 'the switch is remembered, the language is not');
        $this->assertArrayNotHasKey('spoken_language', $this->user->fresh()->dub_options);
        $this->getJson(route('dub.status', $job))->assertJson(['separate' => true]);
    }

    public function test_detect_and_subtitles_only_need_neither(): void
    {
        $this->upload(['spoken_language' => '', 'separate' => '1'])->assertCreated(); // dub is off

        $this->assertSame([null, false], [VideoJob::sole()->spoken_language, VideoJob::sole()->separate]);
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->upload(['spoken_language' => 'la'])->assertUnprocessable()
            ->assertJsonPath('errors.spoken_language.0', 'Choose a spoken language from the list, or Detect it.');
        Queue::assertNothingPushed();
    }

    public function test_the_worker_gets_both(): void
    {
        Queue::fake([]);
        Sleep::fake();
        config(['services.dubber.url' => 'http://worker.test', 'services.dubber.token' => 'test-token']);
        Storage::disk('local')->put('uploads/abc.mp4', 'video');
        Http::fake([
            'worker.test/jobs' => Http::response(['job_id' => 'w1']),
            'worker.test/jobs/w1' => Http::response(['status' => 'failed', 'progress' => 5, 'message' => 'Failed', 'error' => 'No speech was found in the video.']),
        ]);
        $job = $this->user->videoJobs()->create(['original_name' => 'a.mp4', 'path' => 'uploads/abc.mp4', 'spoken_language' => 'ja', 'separate' => true]);

        try {
            (new ProcessVideoJob($job))->handle();
        } catch (\App\Exceptions\DubbingFailed) {
            // the faked worker fails the job at once; only what was sent matters here
        }

        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->pluck('contents', 'name');

            return $request->url() === 'http://worker.test/jobs' && $fields['language'] === 'ja' && $fields['separate'] === 'true';
        });
    }
}
