<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Messages from the worker, and about it, are stored in English and shown in the page's language.
 */
class WorkerMessageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every fixed message a user can see. The worker's come from worker/app.py and worker/stages/*.py:
     * add a new one here and in lang/km.json.
     */
    private const FIXED = [
        // worker
        'FFmpeg is not installed in the worker. Rebuild it with: docker compose build worker',
        'Could not load the translation model. The first run downloads about 2.5 GB, so check the internet connection and free disk space, then try again.',
        'This video seems to be in Khmer already, so there is nothing to translate. Upload a video in another language.',
        'The translation did not produce any Khmer text. Try a video with clearer speech.',
        'No speech was found in the video.',
        'The video file is missing. Please upload it again.',
        'The video has no sound track, so there is nothing to translate.',
        'This file is not a video FFmpeg can read. Please upload an MP4, MOV, MKV or WebM file.',
        'Could not read the sound of this video. Try converting it to MP4 first.',
        'The Khmer voice service (edge-tts) could not be reached. Check the internet connection, or set TTS_ENGINE=mms in worker/.env to use the offline voice.',
        'Could not load the offline Khmer voice. The first run downloads about 145 MB, so check the internet connection, then try again.',
        'Could not prepare a Khmer voice clip.',
        'Could not make the dubbed video. Try converting the upload to MP4 first.',
        'Could not fit a Khmer voice clip to the speech timing.',
        'Something went wrong while processing the video. The worker log has the details.',
        'The worker restarted before this job finished. Please upload the video again.',
        'The worker restarted before the results were ready.',
        'This job is not waiting for review.',
        'This job cannot be edited now. Only a finished job or one waiting for review can.',
        'This job has no saved lines to edit. Please upload the video again.',
        'This job is not queued or running, so there is nothing to cancel.',
        'This job is still queued or running. Cancel it first.',
        'Every line is empty. Keep the Khmer text of at least one line.',
        'The worker could not save the video. Check its free disk space.',
        'The result file is no longer on the worker.',
        'The uploaded video is empty.',
        'Wrong or missing x-token. It must equal WORKER_TOKEN in worker/.env and DUBBER_TOKEN in web/.env.',
        // this site
        Worker::WRONG_TOKEN,
        Worker::UNREACHABLE,
        'The worker no longer has this job. Please upload the video again.',
        'Processing took longer than one hour, so it was stopped. Try a shorter video.',
        'The queue stopped while this video was being processed. Please upload it again.',
        'Something went wrong while dubbing the video. The details are in web/storage/logs/laravel.log.',
        'The uploaded video is missing on the server. Please upload it again.',
        'The worker did not return a job id. Check the worker log: docker compose logs worker',
        'The worker could not process this video.',
        'The worker sent a status this site does not understand. Check the worker log.',
        'Could not load the model that removes the original speech. The first run downloads about 80 MB, so check the internet connection, then try again.',
    ];

    public function test_every_fixed_message_has_its_khmer(): void
    {
        App::setLocale('km');

        foreach (self::FIXED as $message) {
            $this->assertNotSame($message, Worker::say($message), "No Khmer for: {$message}");
        }
    }

    public function test_messages_with_a_value_keep_the_value(): void
    {
        App::setLocale('km');

        $this->assertSame('វីដេអូនេះវែង 20.0 នាទី។ ដែនកំណត់គឺ 15 នាទី។', Worker::say('The video is 20.0 minutes long; the limit is 15 minutes.'));
        $this->assertSame("មិនអាចបកប្រែពីភាសានេះ ('la') ទៅជាភាសាខ្មែរបានទេ។", Worker::say("Translating from this language ('la') to Khmer is not supported."));
        $this->assertSame('សំឡេងខ្មែរមិនអាចអានបន្ទាត់នេះបានទេ៖ សួស្តី: 10%', Worker::say('The Khmer voice could not read this line: សួស្តី: 10%'));
        $this->assertStringContainsString("'.avi'", Worker::say("Unsupported file type '.avi'. Upload an MP4, MOV, MKV or WebM video."));
        $this->assertStringContainsString("'medium'", Worker::say("Could not load the speech model 'medium'. The first run downloads it, so check the internet connection and the WHISPER_MODEL setting, then try again."));
        $this->assertStringContainsString('[3, 7]', Worker::say('These lines have no Khmer text: [3, 7]. Write them in Khmer, or empty them.'));
        $this->assertStringContainsString('HTTP 500', Worker::say('The worker could not send the dubbed video (HTTP 500). Check the worker log: docker compose logs worker'));
    }

    public function test_english_pages_and_unknown_messages_stay_as_they_are(): void
    {
        $this->assertSame('The video is 20.0 minutes long; the limit is 15 minutes.', Worker::say('The video is 20.0 minutes long; the limit is 15 minutes.'));

        App::setLocale('km');
        $this->assertSame('Something new went wrong.', Worker::say('Something new went wrong.'));
    }

    public function test_the_status_shows_a_long_video_error_in_khmer(): void
    {
        $user = User::factory()->create();
        $job = $user->videoJobs()->create(['original_name' => 'a.mp4', 'path' => 'uploads/a.mp4', 'status' => 'failed', 'error' => 'The video is 20.0 minutes long; the limit is 15 minutes.']);

        $this->actingAs($user)->withCredentials()->withCookie('lang', 'km')->getJson(route('dub.status', $job))->assertOk()
            ->assertJson(['error' => 'វីដេអូនេះវែង 20.0 នាទី។ ដែនកំណត់គឺ 15 នាទី។']);
    }
}
