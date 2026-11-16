<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Listening to one line's Khmer voice on the review page, before saving.
 */
class LineVoiceTest extends TestCase
{
    use RefreshDatabase;

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
     * @param  array<string, mixed>  $attributes
     */
    private function waiting(array $attributes = [], ?User $owner = null): VideoJob
    {
        return ($owner ?? $this->user)->videoJobs()->create([
            'original_name' => 'a.mp4', 'path' => 'uploads/a.mp4', 'review' => true, 'status' => 'review',
            'worker_job_id' => 'w1', 'gender' => 'male', ...$attributes,
        ]);
    }

    public function test_the_line_is_voiced_with_the_choices_on_the_page(): void
    {
        Http::fake(['worker.test/jobs/w1/voice' => Http::response('RIFFwav', 200, ['Content-Type' => 'audio/wav', 'x-clip-seconds' => '2.40'])]);

        $this->post(route('dub.voice', $this->waiting()), ['text' => 'សួស្តី', 'speaker' => '1', 'voices' => [0 => 'female', 1 => 'male']])
            ->assertOk()->assertHeader('Content-Type', 'audio/wav')->assertHeader('X-Clip-Seconds', '2.4')
            ->assertContent('RIFFwav');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/jobs/w1/voice'
            && $request->hasHeader('x-token', 'test-token')
            && $request->data() === ['text' => 'សួស្តី', 'gender' => 'male', 'speaker' => 1,
                'speakers' => [['id' => 0, 'gender' => 'female'], ['id' => 1, 'gender' => 'male']]]);
    }

    public function test_the_review_page_has_a_listen_button_for_a_dubbed_video_only(): void
    {
        $lines = ['segments' => [['id' => 0, 'start' => 1.0, 'end' => 2.0, 'text' => 'Hi', 'km' => 'សួស្តី']], 'speakers' => []];
        Http::fake(['worker.test/jobs/w1/segments' => Http::response($lines)]);

        $this->get(route('dub.review', $this->waiting()))->assertOk()->assertSee('class="listen-line"', false)
            ->assertSee(route('dub.voice', VideoJob::sole()));

        $this->get(route('dub.review', $this->waiting(['dub' => false])))->assertOk()->assertDontSee('class="listen-line"', false);
    }

    public function test_a_worker_refusal_is_a_readable_message(): void
    {
        Http::fake(['worker.test/jobs/w1/voice' => Http::response(['detail' => 'This line has no Khmer text to say.'], 422)]);

        $this->postJson(route('dub.voice', $this->waiting()), ['text' => 'Hello'])
            ->assertStatus(422)->assertJson(['message' => 'This line has no Khmer text to say.']);
    }

    public function test_an_unreachable_worker_is_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->postJson(route('dub.voice', $this->waiting()), ['text' => 'ក'])->assertStatus(503);
    }

    public function test_only_the_owner_of_a_dubbed_video_being_edited_can_listen(): void
    {
        Http::fake();

        $this->postJson(route('dub.voice', $this->waiting([], User::factory()->create())), ['text' => 'ក'])->assertForbidden();
        $this->postJson(route('dub.voice', $this->waiting(['dub' => false])), ['text' => 'ក'])->assertStatus(409);
        $this->postJson(route('dub.voice', $this->waiting(['status' => 'processing'])), ['text' => 'ក'])->assertStatus(409);
        $this->postJson(route('dub.voice', $this->waiting()), ['text' => ''])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_listening_is_limited_to_30_lines_a_minute(): void
    {
        Http::fake(['worker.test/jobs/w1/voice' => Http::response('RIFF', 200, ['x-clip-seconds' => '1'])]);
        $job = $this->waiting();

        for ($i = 0; $i < 30; $i++) {
            $this->post(route('dub.voice', $job), ['text' => 'ក'])->assertOk();
        }
        $this->post(route('dub.voice', $job), ['text' => 'ក'])->assertTooManyRequests();
    }
}
