<?php

namespace Tests\Feature;

use App\Exceptions\DubbingFailed;
use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * One voice per speaker: the upload switch, what the worker gets, and the speakers on the review page.
 */
class SpeakersTest extends TestCase
{
    use RefreshDatabase;

    private const LINES = [
        ['id' => 0, 'start' => 1.2, 'end' => 4.8, 'text' => 'Hello there', 'km' => 'សួស្តី', 'speaker' => 0],
        ['id' => 1, 'start' => 5.0, 'end' => 7.0, 'text' => 'Hi', 'km' => 'ជំរាបសួរ', 'speaker' => 1],
    ];

    private const SPEAKERS = [['id' => 0, 'gender' => 'female', 'lines' => 1], ['id' => 1, 'gender' => 'male', 'lines' => 1]];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dubber.url' => 'http://worker.test', 'services.dubber.token' => 'test-token']);
        Storage::fake('local');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * A dubbed job with speakers that waits for review on the worker as "w1".
     */
    private function waiting(): VideoJob
    {
        return $this->user->videoJobs()->create([
            'original_name' => 'talk.mp4', 'path' => 'uploads/abc.mp4', 'review' => true, 'speakers' => true, 'status' => 'review',
            'progress' => 50, 'message' => 'Waiting for your review', 'worker_job_id' => 'w1',
        ]);
    }

    public function test_the_switch_is_on_the_form_and_saved_with_the_job(): void
    {
        $this->get('/dub')->assertOk()->assertSee('name="speakers" value="1"', false)->assertSee('One voice per speaker');

        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4'), 'dub' => '1', 'speakers' => '1', 'original_volume' => '0'],
            ['Accept' => 'application/json'])->assertCreated();

        $job = VideoJob::sole();
        $this->assertTrue($job->speakers);
        $this->assertTrue($this->user->fresh()->dubOptions()['speakers'], 'the switch is remembered');
        $this->getJson(route('dub.status', $job))->assertJson(['speakers' => true]);
    }

    public function test_subtitles_only_need_no_speakers(): void
    {
        $this->post('/dub', ['video' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4'), 'speakers' => '1'], ['Accept' => 'application/json'])->assertCreated();
        $this->assertFalse(VideoJob::sole()->speakers);
    }

    public function test_the_worker_gets_the_choice(): void
    {
        Queue::fake([]);
        Sleep::fake();
        Storage::disk('local')->put('uploads/abc.mp4', 'video');
        Http::fake([
            'worker.test/jobs' => Http::response(['job_id' => 'w1']),
            'worker.test/jobs/w1' => Http::response(['status' => 'failed', 'progress' => 5, 'message' => 'Failed', 'error' => 'No speech was found in the video.']),
        ]);
        $job = $this->user->videoJobs()->create(['original_name' => 'a.mp4', 'path' => 'uploads/abc.mp4', 'speakers' => true]);

        try {
            (new ProcessVideoJob($job))->handle();
        } catch (DubbingFailed) {
            // the faked worker fails the job at once; only what was sent matters here
        }

        Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/jobs'
            && collect($request->data())->pluck('contents', 'name')['speakers'] === 'true');
    }

    public function test_the_review_page_shows_each_speaker_with_a_voice_and_each_line_with_its_speaker(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['segments' => self::LINES, 'speakers' => self::SPEAKERS])]);

        $this->get(route('dub.review', $this->waiting()))->assertOk()
            ->assertSee('Speaker 1')->assertSee('Speaker 2')
            ->assertSee('name="voices[0]" value="female" checked', false)
            ->assertSee('name="voices[1]" value="male" checked', false)
            ->assertSee('name="speaker[1]"', false)
            ->assertDontSee('type="radio" name="gender"', false); // each speaker has a voice, so there is no single one
    }

    public function test_a_job_without_speakers_has_one_voice_and_no_pickers(): void
    {
        $lines = array_map(fn (array $line) => [...$line, 'speaker' => null], self::LINES);
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['segments' => $lines, 'speakers' => []])]);

        $this->get(route('dub.review', $this->waiting()))->assertOk()
            ->assertSee('name="gender"', false)->assertDontSee('name="speaker[0]"', false)->assertDontSee('Speaker 1');
    }

    public function test_saving_sends_the_voices_and_the_moved_lines(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['status' => 'queued'])]);

        $this->post(route('dub.review.save', $this->waiting()), [
            'km' => [0 => 'សួស្តី', 1 => 'ជំរាបសួរ'], 'speaker' => [0 => '1', 1 => '1'], 'voices' => [0 => 'male', 1 => 'female'],
        ])->assertRedirect();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request['segments'] === [['id' => 0, 'km' => 'សួស្តី', 'speaker' => 1], ['id' => 1, 'km' => 'ជំរាបសួរ', 'speaker' => 1]]
            && $request['speakers'] === [['id' => 0, 'gender' => 'male'], ['id' => 1, 'gender' => 'female']]);
    }

    public function test_a_bad_voice_is_refused_before_the_worker(): void
    {
        Http::fake();

        $this->post(route('dub.review.save', $this->waiting()), ['km' => [0 => 'ក'], 'voices' => [0 => 'robot']])
            ->assertSessionHasErrors('voices.0');
        Http::assertNothingSent();
    }
}
