<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The English and Khmer switch: one language at a time, with all the Khmer in lang/km.json.
 */
class LanguageTest extends TestCase
{
    use RefreshDatabase;

    private const KM_HEADLINE = 'បញ្ចូលសំឡេងខ្មែរឱ្យវីដេអូរបស់អ្នក';

    private const EN_HEADLINE = 'Give your video a Khmer voice';

    /**
     * The Khmer translations.
     *
     * @return array<string, string>
     */
    private function khmer(): array
    {
        return json_decode(File::get(lang_path('km.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_english_is_the_default_and_shows_no_khmer(): void
    {
        $this->actingAs(User::factory()->create())->get('/dub')->assertOk()
            ->assertSee('<html lang="en">', false)->assertSee(self::EN_HEADLINE)->assertDontSee(self::KM_HEADLINE);
    }

    public function test_a_khmer_browser_starts_in_khmer(): void
    {
        $this->actingAs(User::factory()->create())->get('/dub', ['Accept-Language' => 'km-KH,km;q=0.9,en;q=0.8'])->assertOk()
            ->assertSee('<html lang="km">', false)->assertSee(self::KM_HEADLINE)->assertDontSee(self::EN_HEADLINE);
    }

    public function test_the_switch_remembers_the_choice_and_goes_back(): void
    {
        $response = $this->from('/login')->post('/language', ['lang' => 'km']);

        $response->assertRedirect('/login')->assertCookie('lang', 'km');
        $this->assertGreaterThan(now()->addDays(300)->getTimestamp(), $response->getCookie('lang')->getExpiresTime());
    }

    public function test_the_choice_beats_the_browser(): void
    {
        $this->withCookie('lang', 'en')->get('/login', ['Accept-Language' => 'km'])->assertSee('<html lang="en">', false);
        $this->withCookie('lang', 'km')->get('/login', ['Accept-Language' => 'en'])->assertSee('<html lang="km">', false)->assertSee('ពាក្យសម្ងាត់');
    }

    public function test_guests_have_the_switch(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="lang"', false);
    }

    public function test_an_unknown_language_is_ignored_or_refused(): void
    {
        $this->post('/language', ['lang' => 'fr'])->assertSessionHasErrors('lang');
        $this->withCookie('lang', 'fr')->get('/login')->assertSee('<html lang="en">', false);
    }

    public function test_job_steps_are_translated(): void
    {
        $user = User::factory()->create();
        $job = $user->videoJobs()->create(['original_name' => 'a.mp4', 'path' => 'uploads/a.mp4', 'status' => 'processing', 'message' => 'Translating to Khmer', 'language' => 'en']);

        $this->actingAs($user)->withCredentials()->withCookie('lang', 'km')->getJson(route('dub.status', $job))->assertOk()
            ->assertJson(['message' => 'កំពុងបកប្រែជាភាសាខ្មែរ', 'language_name' => 'អង់គ្លេស']);
    }

    public function test_known_worker_errors_are_translated(): void
    {
        $user = User::factory()->create();
        $job = $user->videoJobs()->create(['original_name' => 'a.mp4', 'path' => 'uploads/a.mp4', 'status' => 'failed', 'error' => 'This video seems to be in Khmer already, so there is nothing to translate. Upload a video in another language.']);

        $this->actingAs($user)->withCredentials()->withCookie('lang', 'km')->getJson(route('dub.status', $job))->assertOk()
            ->assertJson(['error' => 'វីដេអូនេះហាក់ដូចជាភាសាខ្មែររួចហើយ ដូច្នេះគ្មានអ្វីត្រូវបកប្រែទេ។ សូមផ្ទុកវីដេអូជាភាសាផ្សេង។']);
    }

    public function test_validation_messages_are_translated(): void
    {
        $this->actingAs(User::factory()->create())->withCredentials()->withCookie('lang', 'km')->postJson('/dub', [])
            ->assertUnprocessable()->assertJsonPath('errors.video.0', 'សូមជ្រើសរើសឯកសារវីដេអូ។');
    }

    public function test_every_text_on_the_pages_has_its_khmer(): void
    {
        $khmer = $this->khmer();
        $files = [app_path('Http/Controllers/VideoJobController.php')];
        foreach (['auth', 'components', 'dub', 'errors', 'layouts', 'profile'] as $folder) {
            $files = [...$files, ...File::allFiles(resource_path("views/{$folder}"))];
        }

        foreach ($files as $file) {
            preg_match_all("/__\('((?:[^'\\\\]|\\\\.)+)'/", File::get($file), $matches);
            foreach ($matches[1] as $text) {
                $this->assertArrayHasKey(stripslashes($text), $khmer, "No Khmer for this text in {$file}");
            }
        }
    }

    public function test_every_worker_step_has_its_khmer(): void
    {
        $steps = ['Waiting in the queue', 'Sending the video to the worker', 'Starting', 'Extracting audio', 'Transcribing speech',
            'Translating to Khmer', 'Waiting for your review', 'Making the Khmer voice', 'Mixing and merging', 'Downloading the results', 'Done', 'Failed'];

        foreach ($steps as $step) {
            $this->assertArrayHasKey($step, $this->khmer());
        }
    }
}
