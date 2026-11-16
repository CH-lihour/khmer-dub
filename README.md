# Khmer Dub

Upload a video in any spoken language and get back a Khmer subtitle file (`.srt`) and the
same video with a Khmer voice track. Zero cost: every tool is free and open source and runs
on your own machine. See `CLAUDE.md` for the full specification and build phases.

- `web/`: Laravel 13 site (uploads, queue, progress, downloads)
- `worker/`: FastAPI worker (FFmpeg, faster-whisper, NLLB-200, edge-tts / MMS-TTS)

> Status: all 8 phases are built. Register or log in at http://localhost:8000, upload a video,
> watch its progress, and download the Khmer `.srt` and dubbed `.mp4`. Each user sees only
> their own videos, and everything is deleted after 7 days.

## Requirements

- Git
- Docker Engine with Compose v2 (Linux), or Docker Desktop with WSL 2 integration (Windows).
  No local PHP, Python or FFmpeg is needed.
- **At least 8 GB of RAM for Docker.** Whisper `small` and NLLB-200 together need about 6 to 8 GB.
  On Windows, Docker Desktop with WSL 2 gets half the PC's RAM by default. To raise it,
  put this in `C:\Users\<you>\.wslconfig` and run `wsl --shutdown`:

  ```ini
  [wsl2]
  memory=10GB
  ```

## Install and run

```bash
git clone <repo> khmer-dub && cd khmer-dub
cp web/.env.example web/.env
cp worker/.env.example worker/.env
# Set the same random value for DUBBER_TOKEN (web/.env) and WORKER_TOKEN (worker/.env),
# and change the database passwords in web/.env. Example token: openssl rand -hex 24
docker compose up --build -d                  # first build downloads ~1.5 GB
docker compose ps                             # wait until all five services are "healthy"
docker compose exec web php artisan migrate   # create tables
```

Open http://localhost:8000, click **Register**, and create an account. You then land on the
dubbing page at `/dub`. `APP_KEY` and `web/vendor/` are created automatically on the first start.
The first video takes longer, because the worker downloads the AI models (about 3.5 GB) then.

For development you can also create two test accounts, `test@example.com` and
`second@example.com`, whose password is the default in `web/database/factories/UserFactory.php`:

```bash
docker compose exec web php artisan db:seed
```

## Everyday commands

```bash
docker compose up --build -d                  # start everything
docker compose exec web php artisan migrate   # create tables
docker compose exec web php artisan test      # Laravel tests
docker compose run --rm worker pytest         # worker tests
docker compose logs -f worker                 # worker log
docker compose logs -f queue                  # Laravel queue log (each video job)
docker compose exec web php artisan dub:cleanup   # delete videos older than 7 days now
docker compose restart queue                  # after changing PHP code (queue:work keeps old code)
docker compose down                           # stop; volumes (models, database) are kept
```

Run every worker script and test through `docker compose run --rm worker ...`, never with a local Python.

## Laravel tests

```bash
docker compose exec web php artisan test
```

They run on an in-memory SQLite database and a fake worker, so they never touch MySQL or the
real worker. Compose passes `web/.env` to the container as real environment variables, so
`web/phpunit.xml` forces every test setting in both `<env force="true">` and `<server>`, and
`tests/TestCase.php` refuses to start on any database but in-memory SQLite. The tests also have
their own `APP_KEY` there: on a fresh clone the containers start with an empty one, and only the
site's own process sees the key written to `web/.env` until the containers are recreated
(`docker compose up -d --force-recreate web queue scheduler`).

## Worker tests

```bash
docker compose run --rm worker pytest                  # all tests
docker compose run --rm worker pytest -m "not models"  # skip the tests that load AI models
```

The tests make their own short sample files with FFmpeg. The transcription test also needs an
English voice sample, which it makes with edge-tts. That needs internet, so the test is skipped
when you are offline. The first run downloads the Whisper model (about 480 MB for `small`) and
NLLB-200 (about 2.5 GB) into the `hf_cache` volume. Later runs reuse them. Neither model needs a
Hugging Face account or token.

## Try the stages from the command line

`./worker` is mounted at `/app` in the worker container. Put test videos in `worker/samples/`.
Video files are never committed.

```bash
# Phase 1: print the timed speech segments and the detected language
docker compose run --rm worker python try_transcribe.py samples/test.mp4

# Phase 2: print the original and the Khmer text of the first 20 segments
docker compose run --rm worker python try_translate.py samples/test.mp4
# ...or save them as worker/samples/review.txt for a Khmer speaker to rate
docker compose run --rm -T worker python try_translate.py samples/test.mp4 > worker/samples/review.txt

# Phase 3: make Khmer subtitles; writes worker/samples/test-out/khmer.srt and segments.json
docker compose run --rm worker python try_pipeline.py samples/test.mp4
```

To check the subtitles in VLC:

1. Open the video in VLC (on Windows, from
   `\\wsl.localhost\ubuntu\home\<you>\projects\khmer-dub\worker\samples\`).
2. Drag `test-out\khmer.srt` onto the VLC window, or use **Subtitle > Add Subtitle File...**.
3. Check that each line appears when the speaker starts and disappears when they stop.
4. If the Khmer shows as boxes, choose a Khmer font: **Tools > Preferences > Subtitles / OSD >
   Font**, pick `Khmer UI` (included with Windows 10 and 11), save, and restart VLC.

`khmer.srt` is plain UTF-8 and uses the original speech times. `segments.json` holds each
segment's times with the original and Khmer text, for debugging and review.

```bash
# Phase 8: print who speaks each line (without a video: a sample of three English voices, needs internet)
docker compose run --rm worker python try_speakers.py [samples/test.mp4]

# Phase 4: also make worker/samples/test-out/dubbed.mp4 with a Khmer voice (female or male)
docker compose run --rm worker python try_pipeline.py samples/test.mp4 --dub --voice female
```

The script prints how many clips were sped up, the fastest speed-up, any speech that had to be
cut, and any clip that overlaps the next segment (there should be none).

How the voice is made and fitted:

- `TTS_ENGINE=edge` (default) uses edge-tts with `km-KH-SreymomNeural` (female) or
  `km-KH-PisethNeural` (male). It needs internet and is retried twice after a network error (a "no audio" answer up to 10 times). `TTS_ENGINE=mms` uses
  `facebook/mms-tts-khm` offline (one voice, about 145 MB on the first run).
- Each clip becomes WAV 24 kHz mono, and the silence around the speech is cut (edge-tts adds
  about 0.2 s before and 0.8 s after).
- A clip may run into the silence after its line, but never past the next line's start. Only a
  clip that does not fit before the next line is sped up with FFmpeg `atempo`, at most x1.6, and
  clips are never slowed down. If it still does not fit, its end is cut with a short fade.
- `dubbed.mp4` copies the picture (`-c:v copy`) and adds the Khmer track as AAC, tagged as an
  AI-generated voice. MP4 cannot hold some WebM codecs (VP8, for example). Those videos are
  re-encoded to H.264 instead, which is slower.
- The **Original sound** slider on the upload form sets how loud the original plays under the Khmer
  voice for that video, from Off (only the Khmer voice) to 100%. It starts at Off. Music and
  effects go up and down with the speech, because the original is one mixed track. 15% is about
  16 dB quieter. Because the level stays fixed, the original speech never gets loud between Khmer
  lines.
- The upload form saves each account's voice, dub, original sound and edit choices as soon as one
  changes, so they survive a refresh and follow the account to any browser. A new account starts with the Sreymom voice, dubbing on
  and the original sound Off.
- `ORIGINAL_VOLUME` in `worker/.env` is only the default for `POST /jobs` calls and scripts that
  send no level (`try_pipeline.py`, say). After changing it, run `docker compose up -d worker` so
  the worker reads `worker/.env` again.

Segment times are fitted to the speech. Whisper's own times often start a line early (by up to
2 s on the test clip) or let it run into the next one, so `stages/transcribe.py` does three things:
it asks Whisper for word timestamps, splits a segment wherever two words are 1 s or more apart,
and moves each edge onto the speech found by the Silero VAD (bundled with faster-whisper, MIT).

A sentence split over two lines is then joined, so NLLB translates it whole. It happens only when
the text shows that the sentence goes on: the line ends in a comma or similar, or the next one
starts in lowercase. A line with no punctuation followed by a capital is left alone. Lines more than
2.5 s apart are not joined, and a joined line is at most 12 s long. It gets one subtitle and one
voice clip. Scripts without capitals (Chinese, Thai, Khmer) join only on a comma or similar.

Translation runs locally with NLLB-200 (`facebook/nllb-200-distilled-600M`, target `khm_Khmr`),
12 segments per batch. The Whisper-to-NLLB language map is in `worker/stages/translate.py`.
Khmer videos skip the model, and only lines written in Khmer letters are kept. If there are none
the job stops with a clear message, and so does a translation with no Khmer in it. Latin, Breton
and Hawaiian are not supported, because Whisper can
hear them but NLLB-200 cannot translate them.

## Worker API

`worker/app.py` follows the API contract in `CLAUDE.md`. Every route except `GET /health`
needs the header `x-token` equal to `WORKER_TOKEN` (worker/.env), which must equal
`DUBBER_TOKEN` (web/.env). An empty `WORKER_TOKEN` refuses every call.

| Call | Answer |
| --- | --- |
| `POST /jobs` (multipart `file`, `gender` = `female`/`male`, `dub` = `true`/`false`, `review` = `true`/`false`, `original_volume` = `0` to `1`, `language` = a Whisper code or empty to detect it, `separate` = `true`/`false`, `speakers` = `true`/`false`, `glossary` = JSON `[{"term", "km"}]`, `memory` = JSON `[{"language", "text", "km"}]`) | `{"job_id": "<32 hex>"}` |
| `GET /jobs/{id}` | `{"status", "progress", "message", "error", "language", "done", "total"}` |
| `GET /jobs/{id}/segments` | `{"segments": [{"id", "start", "end", "text", "km", "speaker", "reused"}], "speakers": [{"id", "gender", "lines"}]}`, while in `review` or `done` |
| `POST /jobs/{id}/voice` (JSON `{"text"}`, optional `gender`, `speaker`, `speakers`) | one line's Khmer voice as WAV, header `x-clip-seconds` |
| `POST /jobs/{id}/reopen` (multipart `file`, the original video) | `{"status": "review"}`, to make a done job's results again |
| `POST /jobs/{id}/cancel` | `{"status": <status now>}`, to stop a queued or running job |
| `DELETE /jobs/{id}` | `{"status": "deleted"}`, to delete a stopped job and its folder |
| `PUT /jobs/{id}/segments` (JSON `{"segments": [{"id", "km", "speaker"}]}`, optional `gender`, `original_volume`, `speakers` = `[{"id", "gender"}]`) | `{"status": "queued"}`; saves the Khmer and choices and resumes the job |
| `GET /jobs/{id}/download/srt` | `khmer.srt` |
| `GET /jobs/{id}/download/video` | `dubbed.mp4` (only for `dub=true`) |
| `POST /cleanup` (form `days`, default 7) | `{"deleted": <count>}` |
| `GET /health` | `{"ok": true}` |

- 401 for a wrong or missing token, 404 for an unknown job or a result that is not ready, 422 for
  bad input. Every error has a readable `detail`. An upload is checked with ffprobe right away,
  so a missing file, an unsupported or unreadable format, a video without sound, or one longer
  than `MAX_VIDEO_MINUTES` is refused with 422 before it is queued. If FFmpeg itself is missing,
  the answer is 500.
- `language` is the detected spoken language as a Whisper code (for example `en`), or `null`
  until the Khmer is ready. `error` is `null` unless something failed.
- `gender` defaults to `female`, `dub` to `true`, `review` to `false` and `original_volume` to
  `ORIGINAL_VOLUME` when they are left out.
- The review calls answer 409 for a job that is not in `review`, so saving twice cannot run it twice.
  `reopen` answers 409 for a running or failed job, or one with no saved lines.
- `DELETE /jobs/{id}` answers 409 for a job that is queued or running.
- `cancel` answers 409 for a job that is not queued or running. A job in line stops at once. A
  running one stops at its next progress report. That is within a few seconds while making the
  voice, but it can take a model load, or a batch of 12 lines while translating (about 20 seconds
  in a test on CPU). Mixing finishes the step it is on first.
- Jobs run one at a time on one worker thread, in upload order. Later uploads wait as `queued`.
- Each job lives in `/data/jobs/<id>/` (the `worker_jobs` volume) with a `status.json`. After a
  restart, finished jobs can still be downloaded. A job that was queued or running when the
  worker stopped is marked `failed` and must be uploaded again.
- When a job succeeds, the worker deletes its copy of the upload and its working files (audio,
  voice clips). It keeps `khmer.srt`, `dubbed.mp4`, `segments.json` and `status.json`.
- `POST /cleanup` deletes the folders of finished (done or failed) jobs created more than `days`
  days ago, also ones Laravel never recorded. Queued and running jobs are never deleted.
  Laravel's `dub:cleanup` calls it every day.
- There is no `/docs` page. The worker port is not published, so test it from the `web`
  container, which has `curl` and the token in `$DUBBER_TOKEN`:

```bash
# Put a test video where the web container can read it (./web is mounted at /var/www/html)
mkdir -p web/storage/app/curl-test && cp worker/samples/test.webm web/storage/app/curl-test/

# Health, no token needed
docker compose exec web curl -sS http://worker:8001/health

# Upload; keep the job id in $JOB
JOB=$(docker compose exec -T web sh -c 'curl -sS -H "x-token: $DUBBER_TOKEN" -F file=@storage/app/curl-test/test.webm -F gender=female -F dub=true "$DUBBER_URL/jobs"' | sed -E 's/.*"job_id":"([0-9a-f]+)".*/\1/'); echo "$JOB"

# Status; repeat until "status" is "done" (or "failed")
docker compose exec -e JOB="$JOB" web sh -c 'curl -sS -H "x-token: $DUBBER_TOKEN" "$DUBBER_URL/jobs/$JOB"'

# Download both results into web/storage/app/curl-test/
docker compose exec -e JOB="$JOB" web sh -c 'curl -fsS -H "x-token: $DUBBER_TOKEN" -o storage/app/curl-test/khmer.srt "$DUBBER_URL/jobs/$JOB/download/srt"'
docker compose exec -e JOB="$JOB" web sh -c 'curl -fsS -H "x-token: $DUBBER_TOKEN" -o storage/app/curl-test/dubbed.mp4 "$DUBBER_URL/jobs/$JOB/download/video"'

# Error cases: no token (401), unknown job (404), wrong format (422)
docker compose exec web curl -sS -w ' %{http_code}\n' http://worker:8001/jobs/abc
docker compose exec web sh -c 'curl -sS -w " %{http_code}\n" -H "x-token: $DUBBER_TOKEN" "$DUBBER_URL/jobs/abc"'
docker compose exec web sh -c 'curl -sS -w " %{http_code}\n" -H "x-token: $DUBBER_TOKEN" -F file=@composer.json "$DUBBER_URL/jobs"'
```

## Web app

http://localhost:8000/dub has one page: a file input, a voice choice (female Sreymom or male
Piseth), a **Dub the video** switch (off gives Khmer subtitles only), a progress bar that
follows the upload and then updates every 3 seconds, download links when the job is done, and
your last 20 videos.

**Spoken language** is set to **Detect it**; choose a language when Whisper picks the wrong one
(the list is the languages the worker can translate). **Remove the original speech** (shown while
dubbing) takes the speech out of the original sound and keeps the music and effects, which then
play at the **Original sound** level; turning it on raises that level from Off to 100%. While a
video waits, the page says how many videos are ahead of it. A dubbed MP4 also carries the Khmer
subtitles as a track that players such as VLC can switch on; the `.srt` download stays as well.

The ▶ on each voice plays a short sample from `web/public/voices/` (`female.mp3`, `male.mp3`,
about 27 KB each). They were made once with edge-tts and are not made again on their own. To
remake them, for example after changing the sentence:

```bash
docker compose exec worker edge-tts --voice km-KH-SreymomNeural --text "សួស្តី! ខ្ញុំនឹងអានអត្ថបទខ្មែររបស់អ្នក។" --write-media /tmp/female.mp3
docker compose cp worker:/tmp/female.mp3 web/public/voices/female.mp3
```

and the same with `km-KH-PisethNeural` and `male.mp3`. With `TTS_ENGINE=mms` the video uses the
one offline voice, which sounds like neither sample.

Messages from the worker are stored in English and shown in the page's language by
`App\Support\Worker::say()`. A new worker message needs its Khmer in `web/lang/km.json`, and a
message with a value inside (a length, a file type) also needs a pattern in `Worker::PATTERNS`.
`tests/Feature/WorkerMessageTest.php` lists them.

The look is plain CSS in `web/public/css/site.css`, with no build step. Its colours are tokens at the
top of that file, and light and dark follow the system setting. The checked pattern on every page
and in the progress bar is a krama, drawn with CSS gradients. The font is Kantumruy Pro, a Khmer
typeface. Its files are in `web/public/fonts/` (92 KB in all), so the site never fetches a font from outside.

Every page has an **English / ខ្មែរ** switch and shows one language at a time. A first visit follows
the browser's language, and the choice is kept in a cookie for a year, also after logging out. All
the Khmer text is in `web/lang/km.json`, so a Khmer speaker can review it there. The English text is
the key, and a text with no Khmer entry shows in English. A test fails if a page's text has no entry.

1. `POST /dub` checks the upload (MP4, MOV, MKV or WebM, at most 500 MB) and saves it under a
   random name in `web/storage/app/private/uploads/`. It adds a row to `video_jobs` and queues
   `ProcessVideoJob`.
2. The `queue` service runs the job. It sends the video to the worker (`POST /jobs`), asks for its
   status every 4 seconds and copies the progress, message and language into the row. It then
   downloads the results into `web/storage/app/private/results/<id>/` (`khmer.srt`, and
   `dubbed.mp4` when dubbing). Laravel 13 keeps its private files under
   `storage/app/private/`, not `storage/app/`.
   While it runs, the job's card shows the current step and how far it is: the time reached in the
   video while transcribing, and lines while translating and making the voice. The worker reports
   these as `done` and `total`. Click the step to unfold the list of all steps, where finished ones
   have a tick and a failed one a cross.
   **Cancel** asks first in a dialog that says what happens to the work so far, and **Keep going**,
   Esc or a click outside it leaves the job running. After **Yes, stop**, the card says
   **Cancelling** until it has stopped. A new job ends as **Cancelled**, and you upload the video again to redo it. A job past
   its review goes back to review instead, with your edits and any older video kept. Downloading
   the results can no longer be cancelled.
   **Delete**, on the job's card and on each row of **Your videos**, removes a stopped video for
   good after you confirm: its upload, its results, its row and its folder on the worker. There is
   no undo. A running video has no Delete, so cancel it first. If the worker cannot be reached,
   nothing is deleted and the dialog says why, so no copy stays behind.
3. The page reads `GET /dub/<id>/status` and offers `GET /dub/<id>/download/srt` and `/video`.
   The downloads are named after the upload, for example `my-talk-khmer.mp4`.
4. When a job has a dubbed video, the page plays it in a player with the Khmer subtitles, which
   you can turn off in the player. `GET /dub/<id>/preview/video` answers range requests so the
   player can seek, and `/preview/vtt` turns the SRT into WebVTT, the only format browsers read.
   Every dubbed video under **Your videos** has a **Play** button that opens it in the player, for
   as long as its files are kept. **Play** and the video's name both stay on the page and glide to
   the player over 0.7 s, and so does coming back from the edit page. A device set to reduce
   motion goes straight there. The **×** on the video's card stops it, closes the card and goes
   back to the video in the list. A subtitles-only job has no player. If a browser cannot play the video's codec (MP4 keeps the
   upload's codec), the page says so and the download still works.

A failed job shows the reason: the worker's own message (no speech, too long, voice service
unreachable, and so on), or a hint when the worker is down or the tokens differ. A job stops
after one hour (`$timeout = 3600`) and is not retried (`$tries = 1`).

`queue:work` keeps the code it started with. After changing PHP code or config, run
`docker compose restart queue`. The `web` service picks up changes on the next request.

## Glossary and remembered edits

**Glossary** in the top bar opens a list of names and terms, one a line:

```text
Phnom Penh = ភ្នំពេញ
Sokha = សុខា
NASA
```

`term = Khmer` always puts that Khmer for the term; a term alone is kept as written (a brand
name, say). Case does not matter, and a term is only found as a whole word. It is used for every
video uploaded after saving. The worker swaps each term for a marker (`[1]`, `[2]`) before NLLB
and puts the Khmer back after, so NLLB never sees the name. If NLLB loses a marker, that line is
translated again without the glossary, and the worker log says so.

When you change a line on the review page, the site remembers your Khmer for that original line.
A later video with the same line (same spoken language; case, spacing and the final punctuation
do not count) gets your Khmer without translating it, marked "Your Khmer from an earlier
video" on its review page. This helps most with a series that repeats its intro and outro. The
Glossary page shows how many lines are remembered and can forget them all.

## Editing the Khmer

Machine translation makes mistakes, so a person can correct the Khmer before the voice is made.
Turn on **Edit the Khmer first** when uploading. Without it, a job runs straight through.

1. After translation the job stops at 50% with the status **Needs review**, and the page shows a
   **Check the Khmer** link. The job holds no worker thread and no queue slot while it waits, so
   other videos go ahead of it. It can wait for days, up to the 7 day cleanup.
2. The review page shows each line's time and original text above its Khmer. Correct the text, or
   empty a line to remove it from both the subtitles and the voice. The uploaded video plays at the
   top of the page: **â¶ 0:01 â 0:04** on a line plays just that line, and the line being said is
   marked while the video plays. For a dubbed video, a line whose Khmer looks too long for its
   time is marked "Long for its time" (the voice will be sped up) or "Too long for its time" (its
   end may be cut), so you can shorten it before saving.
   A line with letters but no Khmer ones cannot be saved, because the Khmer voice cannot read it.
   Khmer with a Latin name in it, or a line of only digits, is fine.
3. On a dubbed video, **🔊 Listen** on a line makes its Khmer voice from the text in the box,
   with the voice and speaker chosen on the page, and plays it. The line's hint then gives the
   voice's real length against its time ("The voice is 3.2 s for 2.5 s, so it will be sped up
   ×1.28") instead of the guess. Listening saves nothing; it takes 1 to 2 seconds with edge-tts
   and is limited to 30 lines a minute.
4. **Save and make the voice** (or **Save the subtitles**) resumes the job with your text.

A finished dubbed video can be corrected later, too. Open it with **Play** and choose **Edit and
regenerate**. The same page opens, with the voice and **Original sound** to change as well, and
**Save and regenerate** makes a new voice and video from your text. Speech recognition and
translation do not run again, and only the lines whose Khmer or voice changed are voiced again
(a line you listened to is not voiced twice), so this takes little more than the mix. Only the Khmer
can be edited, not the original text. The current video stays until the new one has arrived, and
if making it fails, the old one stays and the page shows the reason. Editing works until the
video's files are deleted, 7 days after upload, and regenerating does not extend that.
Subtitles-only jobs cannot be edited this way yet.

Your edits are kept if something goes wrong after saving. If the voice service is down, the job
returns to **Needs review** with the reason, and saving again retries it. If the worker restarts,
the job goes on by itself; only if it restarts a second time during the same run does the job
return to **Needs review** with the reason.

## One voice per speaker

Turn on **One voice per speaker** (with **Dub the video**) for a video with more than one person
talking. After transcription the worker makes a voice print of every line with
`microsoft/wavlm-base-plus-sv`, groups the lines that sound alike into speakers, and guesses from
each speaker's pitch whether Sreymom (female) or Piseth (male) fits. Speakers with the same voice
are pitched apart (0, -2, +2, -3, +3, -1 semitones), so two women still sound different. It takes
a few seconds; the first time it downloads the model (about 400 MB) into `hf_cache`.

It is a guess, so use it with **Edit the Khmer first**. The review page then lists the speakers,
each with its voice, and every line has a speaker picker to move it to someone else. Two settings
in `worker/.env` tune it: `SPEAKER_THRESHOLD` (default 0.85; lower joins more lines into one
speaker) and `MAX_SPEAKERS` (default 6).

## Accounts

Every page except login, registration and password reset needs an account. The login code was
scaffolded once with Laravel Breeze (MIT). Its views were rewritten in plain Blade and CSS
(`web/public/css/site.css`), so there is no Tailwind, npm or Node build. The Breeze package
itself was removed again, because nothing uses it after scaffolding.

- **Register** at `/register`, **log in** at `/login`, change your name, email or password or
  delete your account at `/profile` (click your name at the top).
- Each video belongs to the user who uploaded it. The page lists only your videos. Another user's
  `/dub/<id>/status` or `/dub/<id>/download/...` answers 403 "This video belongs to another
  account."
- Email (password reset links) goes to Mailpit, a local inbox: open http://localhost:8025. It
  never leaves your computer, and Mailpit forgets it when it restarts. `compose.yaml` sets the
  `MAIL_` settings for this over `web/.env`.
- Deleting your account deletes your videos, uploads and results at once. The worker's copies
  go at the next daily cleanup once they are 7 days old.
- Email addresses are not verified. That is fine on your own machine. Do not put this site
  on the internet as it is.

## Cleanup

`php artisan dub:cleanup` deletes everything older than 7 days:

1. every video row created more than 7 days ago, with its upload and its results;
2. uploads that no row uses (older than 7 days), and result folders whose row is gone;
3. on the worker, through `POST /cleanup`, the folders of finished jobs created more than 7 days ago.

The `scheduler` service runs it every day at 00:00 UTC (07:00 in Cambodia). To run it by hand,
or with another age:

```bash
docker compose exec web php artisan dub:cleanup
docker compose exec web php artisan dub:cleanup --days=1
docker compose exec web php artisan schedule:list   # shows when it runs next
```

If the worker cannot be reached, the site's own files are still deleted and the command fails
with a message; the worker's folders are deleted on the next run.

**PHP upload limits.** `web/docker/php.ini` sets `upload_max_filesize = 512M` and
`post_max_size = 520M`, which allow the 500 MB (512000 KB) the upload form accepts. To change them,
edit that file, rebuild both images that use it, and check the values:

```bash
docker compose up -d --build web queue
docker compose exec web php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("post_max_size"), PHP_EOL;'
```

Keep `post_max_size` a little above `upload_max_filesize`, and change the `max:512000` rule in
`web/app/Http/Controllers/VideoJobController.php` to match.

## Services

| Service  | Image                         | Role                              | Port |
| -------- | ----------------------------- | --------------------------------- | ---- |
| `web`    | `php:8.5.12-cli-trixie` + Composer 2.10 | `php artisan serve` (4 PHP processes) | 8000 |
| `queue`  | same as `web`                 | `php artisan queue:work --timeout=3700` | none |
| `scheduler` | same as `web`              | `php artisan schedule:work` (daily `dub:cleanup`) | none |
| `mysql`  | `mysql:9.7.4` (LTS)           | database                          | none |
| `worker` | `python:3.14.10-slim-trixie` + FFmpeg 7.1 | FastAPI on `worker:8001` | none |
| `reverb` | same as `web` | `php artisan reverb:start`, live progress for the page | 8080 (this computer only) |
| `mailpit` | `axllent/mailpit:v1.33.1` | catches the site's email; inbox at http://localhost:8025 | 8025 (this computer only) |

On a new database the `queue` service waits, printing "waiting for php artisan migrate",
until you run the migrate command. Then it becomes healthy. `queue` and `scheduler` start after
`web` is healthy, so only `web` runs the first `composer install` and `APP_KEY` setup.

The worker port is never published; only the Laravel containers reach it on the Compose network.
Volumes: `mysql_data` (database), `worker_jobs` (`/data/jobs`), `hf_cache` (downloaded models).
In development `./web` and `./worker` are bind-mounted into their containers, so code changes
need no rebuild. Rebuild only after changing a Dockerfile or `requirements.txt`.

## Live progress

While a video is processed, the page gets its progress the moment it changes from the `reverb`
container (Laravel Reverb, self-hosted, MIT) instead of asking every 3 seconds. The browser
connects to `ws://<the page's host>:8080` with a small script, `web/public/js/live.js`, that
speaks Reverb's Pusher protocol, so no npm package or CDN is needed. Each user can only join
their own private channel (`routes/channels.php`).

It is optional. If Reverb is stopped (`docker compose stop reverb`), the page goes back to
asking every 3 seconds and reconnects by itself when Reverb is back. The site then waits about
4 s once to give up on Reverb and does not try again for a minute, so a video is never failed or
held up by it. `BROADCAST_CONNECTION=null` in `web/.env` turns it off. On a fresh install, set
`REVERB_APP_KEY` and `REVERB_APP_SECRET` in `web/.env` to random values (for example
`openssl rand -hex 20`). Port 8080 is published to this computer only, so a page opened from
another device (or through the tunnel below) polls instead.

## Share the site from this computer (Cloudflare quick tunnel, free)

`compose.tunnel.yaml` adds a `cloudflared` service (`cloudflare/cloudflared`, Apache-2.0) that gives
the site a public HTTPS address on `trycloudflare.com`. It needs no Cloudflare account, no domain
and no router change, and works behind CGNAT. Nothing else is published: the tunnel reaches only
`web`, and port 8000 is then open to this computer only.

```bash
docker compose -f compose.yaml -f compose.tunnel.yaml up --build -d
docker compose -f compose.yaml -f compose.tunnel.yaml logs cloudflared | grep trycloudflare.com
```

Open the `https://<random-words>.trycloudflare.com` address it prints, and share it. Naming the
files with `-f` skips `compose.override.yaml`; if you have one, add `-f compose.override.yaml`
before the tunnel file. To stop sharing and open port 8000 to the local network again:

```bash
docker compose up -d --remove-orphans
```

Limits:

- The address is random and changes every time `cloudflared` restarts (or the computer does).
  A fixed address needs a free Cloudflare account and your own domain (a "named tunnel").
- Cloudflare's free proxy refuses uploads over **100 MB**; the page then says the video is too
  large. Use shorter or smaller videos, or the site directly on http://localhost:8000.
- Quick tunnels are meant for testing, have no uptime promise, and Cloudflare may limit them.
- The site is online only while this computer is on, awake and running Docker (on Windows,
  turn off sleep while sharing).
- Anyone with the address reaches the login page, and anyone can register an account.

## Online demo on Hugging Face Spaces (free)

To keep the site online while your computer is off, you can run it on a free Hugging Face Space
(Docker, "CPU basic": 2 CPUs, 16 GB RAM). This needs a free Hugging Face account, with no payment
card. Everything runs in **one container** made from `deploy/hf-space/`:

| Inside the container | Role |
| -------------------- | ---- |
| `php artisan serve` on port 7860 | the site, the only public port |
| `php artisan queue:work`, `php artisan schedule:work` | queue and daily cleanup |
| `uvicorn` on `127.0.0.1:8001` | the worker, reachable only inside the container, token checked |
| SQLite at `/data/db/database.sqlite` | database instead of MySQL |
| `/opt/models` | Whisper, NLLB, Demucs and MMS, downloaded while the image builds |

`start.sh` makes a random worker token and `APP_KEY` at every start, so the Space needs no secrets.
Your local `compose.yaml` setup is not changed by any of this.

**Steps (in the WSL terminal, from the repository root):**

1. On https://huggingface.co sign up (free), then **New Space**: choose **Docker**, the **Blank**
   template and the free **CPU basic** hardware. Public means anyone can see the code and use the site;
   private means only you, while logged in to Hugging Face.
2. Make a token with **write** access at https://huggingface.co/settings/tokens.
3. Assemble the Space folder (`build/hf-space`, ignored by Git) and upload it. The upload script
   asks for the token without showing or saving it, and runs `hf` inside the worker container:

   ```bash
   bash deploy/hf-space/build.sh
   bash deploy/hf-space/upload.sh <your-hf-name>/<space-name>
   ```

4. The Space builds the image (about 15 to 25 minutes the first time) and starts. Open it at its own
   address, `https://<your-hf-name>-<space-name>.hf.space`. Logins may fail inside the frame on the
   huggingface.co page.

Run steps 3 again after every change. To test the same image on your computer first:

```bash
bash deploy/hf-space/build.sh
docker build -t khmer-dub-space build/hf-space
docker run --rm -p 7860:7860 -e SESSION_SECURE_COOKIE=false -e SESSION_SAME_SITE=lax khmer-dub-space
```

then open http://localhost:7860.

**Limits of the free Space:**

- **Nothing is kept.** Each restart wipes accounts, uploads, results and job history. A restart comes
  with every upload of new code, after about 48 hours without visitors (the Space sleeps; the next
  visit wakes it in a few minutes), and whenever Hugging Face moves it. Persistent storage is a paid
  add-on, so it is not used. Download results right away.
- Password reset emails are not sent (`MAIL_MAILER=log`).
- The image is about 14 GB (PyTorch and the models), so each build on the Space takes a while.
- Two CPUs: transcription and the voice are about as slow as on a laptop, or slower.
- Large uploads have not been tested against Hugging Face's proxy; if 500 MB uploads fail, use smaller videos.
- `edge-tts` needs internet, which the Space has. Set the Space variable `TTS_ENGINE=mms` for the offline voice.
- Keep the image tags and `TORCH_VERSION` in `deploy/hf-space/Dockerfile` the same as in
  `web/Dockerfile` and `worker/Dockerfile`.

## Versions

Resolved on 2026-10-30: Laravel 13.39, PHP 8.5.12, Python 3.14.10, MySQL 9.7.4 LTS,
PyTorch 2.15.0 (CPU), FastAPI 0.162, faster-whisper 1.2.1, transformers 5.22.1.
Python 3.14 is used because every package (torch, ctranslate2, sentencepiece, onnxruntime, av)
has a 3.14 wheel. Image tags are pinned in the Dockerfiles and `compose.yaml`, Python packages
in `worker/requirements.txt` (torch in `worker/Dockerfile`), PHP packages in `web/composer.lock`.
faster-whisper 1.2.1 cannot open files with the pinned PyAV 19, so `stages/transcribe.py` reads
the 16 kHz WAV itself and passes Whisper the samples.

## Licenses (all free for personal and student use)

| Component | License |
| --- | --- |
| Laravel Reverb, pusher-php-server, ratchet/rfc6455, ReactPHP, clue/redis-react (used by Reverb) | MIT |
| Laravel, FastAPI, faster-whisper, CTranslate2, pydub, pytest, Demucs, einops, julius, Mailpit | MIT |
| sphn (used by Demucs) | Apache-2.0 |
| lameenc (used by Demucs) | LGPL-3.0 |
| Demucs `htdemucs` model weights | MIT |
| Laravel Breeze (used once to scaffold the login code, then removed) | MIT |
| Kantumruy Pro font (`web/public/fonts/`, license in `OFL.txt`) | SIL OFL 1.1 |
| Uvicorn, PyTorch, httpx | BSD-3-Clause |
| transformers, sentencepiece, python-multipart | Apache-2.0 |
| edge-tts | LGPL-3.0 |
| audioop-lts (needed by pydub on Python 3.13+) | PSF-2.0 |
| FFmpeg (Debian build) | LGPL/GPL |
| MySQL Community Server | GPL-2.0 |
| Whisper model weights | MIT |
| NLLB-200 and MMS-TTS model weights | CC-BY-NC-4.0 (non-commercial use only) |
| WavLM-Base-Plus-SV speaker model weights (Microsoft UniSpeech) | CC BY-SA 3.0 |

## Known limits

- Free translation (NLLB) is less natural than a paid LLM and can mistranslate names and numbers.
  Khmer output needs review by a Khmer speaker.
- The glossary puts a term where NLLB put its marker, which follows the original word order. That
  is right for names and nouns, but an adjective can land in English order ("American space
  exploration" came out as អាមេរិកាំង ការស្រាវជ្រាវអវកាស). Use it for names and terms,
  not for describing words. Remembered edits only match a whole line, not part of one.
- Translating each line together with the lines around it was tried and dropped: NLLB 600M then
  wrote Sinhala letters into Khmer words, cut words short, and gave one line the next line's
  translation.
- NLLB puts a space between every Khmer word, which normal Khmer writing does not do.
  `KHMER_SPACES=remove` in `worker/.env` joins the Khmer words (spaces next to punctuation, digits
  and Latin words stay). It is off until a Khmer speaker has compared both. Without spaces, a
  player that cannot break Khmer words may not wrap a long subtitle line.
- With plain beam search, NLLB can loop on short or list-like lines ("អ៊ូ! អ៊ូ! អ៊ូ! ...").
  The worker blocks repeated 3-word sequences to stop this. As a side effect, it may reword a
  phrase that really is said twice in one line.
- Translation runs NLLB in CTranslate2 with int8 weights: 3.6 s for the 8 lines of the 1 minute
  test clip, against 30 s in transformers. The output is almost the same; on that clip 4 of 8 lines
  came out word for word the same and the rest differed by a word or two. The first job converts
  the model once (about 30 s, 630 MB more in `hf_cache`).
- `edge-tts` is unofficial and needs internet. It may change or stop working. Set `TTS_ENGINE=mms`
  for the fully offline (more robotic) voice. At busy times it sends no audio for 7 to 37% of
  ordinary lines at random, and the same line works a second later. The worker tries such a line up
  to 10 times, so a long video still gets through; if one line fails all 10, the job goes back to
  review with "The Khmer voice could not read this line", and saving again goes on from that line
  (the lines already voiced are kept).
- MMS-TTS cannot read digits or Latin letters, and NLLB writes numbers as Khmer digits
  (`៥, ៤, ៣`), so with `mms` the numbers are silent.
- The original music and sound effects stay in the dubbed video, but so does the original speech,
  quietly under the Khmer voice, unless **Remove the original speech** is on. That runs Demucs
  (MIT) and takes about half the video length on CPU (34 s for the 1 minute test clip); the first
  time it downloads its model (about 80 MB). Demucs is not perfect: some speech can remain, and
  singing is removed with the speech. The sound is mono.
- Khmer is often longer than the original, so the voice can keep talking after the speaker has
  stopped (up to 0.8 s on the test clip). Lines with no silence after them are sped up instead,
  and can still lose the end of their speech even at x1.6.
- Making the voice takes about 2 seconds per line with edge-tts. Each job keeps its voice clips
  until cleanup, so editing it later is quick; that is about 3 MB per minute of speech in the
  `worker_jobs` volume.
- **One voice per speaker** is a guess from voice prints. People who sound alike can be merged,
  one person recorded in two places can be split, lines under 1 s take the speaker next to them,
  and people talking over each other are not separated. The male or female guess comes from pitch
  only. There are just two Khmer voices, so a third speaker differs from the first by pitch only,
  and a voice moved in pitch sounds slightly less natural.
- CPU transcription is slow, roughly a few minutes for a 10 minute video with the `small` model.
- The worker runs one job at a time, so an upload can wait in the queue behind a long video; the
  page says how many videos are ahead. A worker restart puts the jobs it was working on back in
  line, going on from the voice if the Khmer was ready, or else from the start. If the same run is
  interrupted twice, the job fails (or goes back to review), in case it is what stops the worker.
- `php artisan serve` is a development server with four PHP processes, and a slow upload or a
  video open in the player holds one each. About four at once make the site wait.
- The Khmer text of the site (`web/lang/km.json`) was written by AI and needs review by a Khmer
  speaker. A few worker messages only a developer can cause (a wrong API call, an unknown
  `TTS_ENGINE`) stay in English, as does any new worker message until its Khmer is added.
- The review page edits text only. It cannot change a line's timing or split or join lines. Its
  player plays the uploaded video, which a browser may not be able to play (MKV, or MOV with
  HEVC); then keep the video open in another player. The "Long for its time" and "Too long" marks
  are an estimate (about 15 Khmer letters a second with edge-tts), not a measurement.
- Email goes only to Mailpit on this computer (http://localhost:8025), never to a real inbox, and
  email addresses are not verified. The site is meant for your own machine, not the internet
  (`php artisan serve`, debug mode on, no HTTPS).
- Choosing the spoken language helps when detection picks the wrong one, but Whisper still writes
  what it hears in that language: choosing the wrong language gives a wrong transcript.
- Videos, results and worker job folders are deleted 7 days after upload. Download what you
  want to keep.
- Whisper `small` sometimes mishears words (for example "timed" as "time"), and over music or
  noise it can invent short phrases such as "Thank you." `WHISPER_MODEL=medium` is more accurate
  but needs about twice the memory and time.
- Whisper `small` could not write Khmer speech in Khmer letters in our test. It recognised a dubbed
  Khmer video as Khmer (91% sure), but wrote Sinhala, Bengali and Latin junk, even with the language
  forced. So a video already in Khmer usually stops with a clear message, and a dubbed file from
  this site cannot be dubbed again: upload the original video. `medium` was not tested.
- Speech detection can miss quiet or crackly speech (old recordings, distant voices). Then a line's
  end can be cut a little short, by up to about 0.5 s.
- Subtitles keep the exact speech times. A very short line can disappear before it is read, and
  a long one is shown as one line that the player wraps. Joining a split sentence makes that line
  longer, and its voice does not stop for a pause the speaker made in the middle of it.
- NLLB-200 and MMS-TTS weights are licensed for non-commercial use only.
- Only use videos you own or may translate; label dubbed videos as AI-generated voice.
