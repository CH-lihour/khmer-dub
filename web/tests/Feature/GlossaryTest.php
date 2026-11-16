<?php

namespace Tests\Feature;

use App\Exceptions\DubbingFailed;
use App\Jobs\ProcessVideoJob;
use App\Models\TranslationMemory;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The glossary page, the memory of review edits, and what the worker gets of both.
 */
class GlossaryTest extends TestCase
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

    public function test_the_glossary_is_saved_from_its_lines_and_shown_again(): void
    {
        $this->put(route('glossary.update'), ['glossary' => "Phnom Penh = ភ្នំពេញ\r\n\n  NASA  \nSokha =សុខា\nsokha = សុខ"])
            ->assertRedirect(route('glossary.edit'));

        $this->assertSame([
            ['term' => 'Phnom Penh', 'km' => 'ភ្នំពេញ'], ['term' => 'NASA', 'km' => ''], ['term' => 'sokha', 'km' => 'សុខ'],
        ], $this->user->fresh()->glossaryEntries(), 'a term given twice keeps the last');
        $this->get(route('glossary.edit'))->assertOk()->assertSee("Phnom Penh = ភ្នំពេញ\nNASA\nsokha = សុខ");
    }

    public function test_a_bad_line_is_refused_with_its_number(): void
    {
        $this->from(route('glossary.edit'))->put(route('glossary.update'), ['glossary' => "NASA\n= ភ្នំពេញ"])
            ->assertSessionHasErrors(['glossary' => 'Line 2 has Khmer but no term before the =.']);
        $this->put(route('glossary.update'), ['glossary' => str_repeat('x', 101)])
            ->assertSessionHasErrors('glossary');
        $this->assertSame([], $this->user->fresh()->glossaryEntries());
    }

    public function test_the_top_bar_links_to_the_glossary(): void
    {
        $this->get('/dub')->assertOk()->assertSee(route('glossary.edit'));
    }

    /**
     * Save the review of a job in English with one changed line, one left as it was and one emptied.
     */
    private function review(string $language = 'en'): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['status' => 'queued'])]);
        $job = $this->user->videoJobs()->create([
            'original_name' => 'a.mp4', 'path' => 'uploads/a.mp4', 'review' => true, 'status' => 'review', 'worker_job_id' => 'w1', 'language' => $language,
        ]);

        $this->post(route('dub.review.save', $job), [
            'km' => [0 => ' សូមស្វាគមន៍ ', 1 => 'លា', 2 => ''],
            'text' => [0 => 'Welcome back', 1 => 'Bye', 2 => 'Um'],
            'was' => [0 => 'ស្វាគមន៍', 1 => 'លា', 2 => 'អឺ'],
        ])->assertRedirect();
    }

    public function test_only_changed_lines_are_remembered(): void
    {
        $this->review();

        $this->assertSame([['en', 'Welcome back', 'សូមស្វាគមន៍']],
            TranslationMemory::all()->map(fn ($line) => [$line->language, $line->source, $line->km])->all());
        $this->get(route('glossary.edit'))->assertSee('1 line you corrected is remembered.');
    }

    public function test_correcting_the_same_line_again_replaces_it(): void
    {
        $this->review();
        TranslationMemory::sole()->update(['km' => 'old']);
        $this->review();

        $this->assertSame('សូមស្វាគមន៍', TranslationMemory::sole()->km);
    }

    public function test_a_khmer_video_is_not_remembered(): void
    {
        $this->review('km');
        $this->assertSame(0, TranslationMemory::count());
    }

    public function test_the_edits_can_be_forgotten(): void
    {
        $this->review();
        $this->delete(route('glossary.forget'))->assertRedirect(route('glossary.edit'));
        $this->assertSame(0, TranslationMemory::count());
    }

    public function test_the_worker_gets_the_glossary_and_the_memory(): void
    {
        Queue::fake([]);
        Sleep::fake();
        Storage::disk('local')->put('uploads/abc.mp4', 'video');
        Http::fake([
            'worker.test/jobs' => Http::response(['job_id' => 'w1']),
            'worker.test/jobs/w1' => Http::response(['status' => 'failed', 'progress' => 5, 'message' => 'Failed', 'error' => 'No speech was found in the video.']),
        ]);
        $this->user->forceFill(['glossary' => [['term' => 'Sokha', 'km' => 'សុខា']]])->save();
        TranslationMemory::remember($this->user, 'en', [['Welcome back', 'ស្វាគមន៍', 'សូមស្វាគមន៍']]);
        $job = $this->user->videoJobs()->create(['original_name' => 'a.mp4', 'path' => 'uploads/abc.mp4']);

        try {
            (new ProcessVideoJob($job))->handle();
        } catch (DubbingFailed) {
            // the faked worker fails the job at once; only what was sent matters here
        }

        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->pluck('contents', 'name');

            return $request->url() === 'http://worker.test/jobs'
                && json_decode($fields['glossary'], true) === [['term' => 'Sokha', 'km' => 'សុខា']]
                && json_decode($fields['memory'], true) === [['language' => 'en', 'text' => 'Welcome back', 'km' => 'សូមស្វាគមន៍']];
        });
    }

    public function test_another_users_memory_is_not_sent(): void
    {
        $other = User::factory()->create();
        TranslationMemory::remember($other, 'en', [['Hello', 'ក', 'ខ']]);

        $this->assertSame(0, $this->user->translationMemories()->count());
    }

    public function test_the_review_page_marks_a_line_from_the_memory(): void
    {
        Http::fake(['worker.test/jobs/w1/segments' => Http::response(['segments' => [
            ['id' => 0, 'start' => 1.0, 'end' => 2.0, 'text' => 'Welcome back', 'km' => 'សូមស្វាគមន៍', 'speaker' => null, 'reused' => true],
            ['id' => 1, 'start' => 3.0, 'end' => 4.0, 'text' => 'Bye', 'km' => 'លា', 'speaker' => null, 'reused' => false],
        ], 'speakers' => []])]);
        $job = $this->user->videoJobs()->create(['original_name' => 'a.mp4', 'path' => 'uploads/a.mp4', 'status' => 'review', 'worker_job_id' => 'w1']);

        $this->get(route('dub.review', $job))->assertOk()
            ->assertSeeTextInOrder(['Welcome back', 'Your Khmer from an earlier video', 'Bye'])
            ->assertSee('name="was[1]" value="លា"', false);
    }
}
