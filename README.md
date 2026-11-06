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
`tests/TestCase.php` refuses to start on any database but in-memory SQLite.

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
# Phase 4: also make worker/samples/test-out/dubbed.mp4 with a Khmer voice (female or male)
docker compose run --rm worker python try_pipeline.py samples/test.mp4 --dub --voice female
```

The script prints how many clips were sped up, the fastest speed-up, any speech that had to be
cut, and any clip that overlaps the next segment (there should be none).

How the voice is made and fitted:

- `TTS_ENGINE=edge` (default) uses edge-tts with `km-KH-SreymomNeural` (female) or
  `km-KH-PisethNeural` (male). It needs internet and is retried twice. `TTS_ENGINE=mms` uses
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
| `POST /jobs` (multipart `file`, `gender` = `female`/`male`, `dub` = `true`/`false`, `review` = `true`/`false`, `original_volume` = `0` to `1`) | `{"job_id": "<32 hex>"}` |
| `GET /jobs/{id}` | `{"status", "progress", "message", "error", "language"}` |
| `GET /jobs/{id}/segments` | `{"segments": [{"id", "start", "end", "text", "km"}]}`, while in `review` or `done` |
| `POST /jobs/{id}/reopen` (multipart `file`, the original video) | `{"status": "review"}`, to make a done job's results again |
| `PUT /jobs/{id}/segments` (JSON `{"segments": [{"id", "km"}]}`, optional `gender`, `original_volume`) | `{"status": "queued"}`; saves the Khmer and choices and resumes the job |
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
updates every 3 seconds, download links when the job is done, and your last 20 videos.

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
3. The page reads `GET /dub/<id>/status` and offers `GET /dub/<id>/download/srt` and `/video`.
   The downloads are named after the upload, for example `my-talk-khmer.mp4`.
4. When a job has a dubbed video, the page plays it in a player with the Khmer subtitles, which
   you can turn off in the player. `GET /dub/<id>/preview/video` answers range requests so the
   player can seek, and `/preview/vtt` turns the SRT into WebVTT, the only format browsers read.
   Every dubbed video under **Your videos** has a **Play** button that opens it in the player, for
   as long as its files are kept. **Play** and the video's name both stay on the page and glide to
   the player over 0.7 s, and so does coming back from the edit page. A device set to reduce
   motion goes straight there. A subtitles-only job has no player. If a browser cannot play the video's codec (MP4 keeps the
   upload's codec), the page says so and the download still works.

A failed job shows the reason: the worker's own message (no speech, too long, voice service
unreachable, and so on), or a hint when the worker is down or the tokens differ. A job stops
after one hour (`$timeout = 3600`) and is not retried (`$tries = 1`).

`queue:work` keeps the code it started with. After changing PHP code or config, run
`docker compose restart queue`. The `web` service picks up changes on the next request.

## Editing the Khmer

Machine translation makes mistakes, so a person can correct the Khmer before the voice is made.
Turn on **Edit the Khmer first** when uploading. Without it, a job runs straight through.

1. After translation the job stops at 50% with the status **Needs review**, and the page shows a
   **Check the Khmer** link. The job holds no worker thread and no queue slot while it waits, so
   other videos go ahead of it. It can wait for days, up to the 7 day cleanup.
2. The review page shows each line's time and original text above its Khmer. Correct the text, or
   empty a line to remove it from both the subtitles and the voice.
   A line with letters but no Khmer ones cannot be saved, because the Khmer voice cannot read it.
   Khmer with a Latin name in it, or a line of only digits, is fine.
3. **Save and make the voice** (or **Save the subtitles**) resumes the job with your text.

A finished dubbed video can be corrected later, too. Open it with **Play** and choose **Edit and
regenerate**. The same page opens, with the voice and **Original sound** to change as well, and
**Save and regenerate** makes a new voice and video from your text. Speech recognition and
translation do not run again, so this takes about 2 seconds per line plus the mix. Only the Khmer
can be edited, not the original text. The current video stays until the new one has arrived, and
if making it fails, the old one stays and the page shows the reason. Editing works until the
video's files are deleted, 7 days after upload, and regenerating does not extend that.
Subtitles-only jobs cannot be edited this way yet.

Your edits are kept if something goes wrong after saving. If the voice service is down or the
worker restarts, the job returns to **Needs review** with the reason, and saving again retries it.

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
- No real email is sent (`MAIL_MAILER=log`). A password reset link is written to
  `web/storage/logs/laravel.log`; copy it from there.
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

On a new database the `queue` service waits, printing "waiting for php artisan migrate",
until you run the migrate command. Then it becomes healthy. `queue` and `scheduler` start after
`web` is healthy, so only `web` runs the first `composer install` and `APP_KEY` setup.

The worker port is never published; only the Laravel containers reach it on the Compose network.
Volumes: `mysql_data` (database), `worker_jobs` (`/data/jobs`), `hf_cache` (downloaded models).
In development `./web` and `./worker` are bind-mounted into their containers, so code changes
need no rebuild. Rebuild only after changing a Dockerfile or `requirements.txt`.

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
| Laravel, FastAPI, faster-whisper, CTranslate2, pydub, pytest | MIT |
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

## Known limits

- Free translation (NLLB) is less natural than a paid LLM and can mistranslate names and numbers.
  Khmer output needs review by a Khmer speaker.
- NLLB puts a space between every Khmer word, which normal Khmer writing does not do.
- With plain beam search, NLLB can loop on short or list-like lines ("អ៊ូ! អ៊ូ! អ៊ូ! ...").
  The worker blocks repeated 3-word sequences to stop this. As a side effect, it may reword a
  phrase that really is said twice in one line.
- CPU translation takes about 4 to 5 seconds per segment (37 s for the 8 segments of the 1 minute
  test clip), longer than transcription.
- `edge-tts` is unofficial and needs internet. It may change or stop working. Set `TTS_ENGINE=mms`
  for the fully offline (more robotic) voice. It sometimes sends no audio for an ordinary
  sentence. The worker retries, and the job fails with a message after three tries.
- MMS-TTS cannot read digits or Latin letters, and NLLB writes numbers as Khmer digits
  (`៥, ៤, ៣`), so with `mms` the numbers are silent.
- The original music and sound effects stay in the dubbed video, but so does the original speech,
  quietly under the Khmer voice. Removing only the speech would need a separation tool such as
  Demucs (MIT), about 1.5 to 3 times the video length on CPU. The sound is mono.
- Khmer is often longer than the original, so the voice can keep talking after the speaker has
  stopped (up to 0.8 s on the test clip). Lines with no silence after them are sped up instead,
  and can still lose the end of their speech even at x1.6.
- Making the voice takes about 2 seconds per line with edge-tts.
- CPU transcription is slow, roughly a few minutes for a 10 minute video with the `small` model.
- The worker runs one job at a time, so an upload can wait in the queue behind a long video.
  Restarting the worker fails the job that was running.
- The page cannot show how far a large upload has got; it shows "Uploading" until the server has
  the whole file. `php artisan serve` is a development server with four PHP processes, and a slow
  upload or a video open in the player holds one each. About four at once make the site wait.
- The Khmer text of the site (`web/lang/km.json`) was written by AI and needs review by a Khmer
  speaker. Error messages from the worker stay in English, also on the Khmer pages.
- The review page edits text only. It cannot change a line's timing, split or join lines, or play
  the video, so keep the video open next to it. An edited line that is much longer than the
  original may be sped up or cut like any other.
- No email is sent, so password reset links must be copied from `web/storage/logs/laravel.log`,
  and email addresses are not verified. The site is meant for your own machine, not the internet
  (`php artisan serve`, debug mode on, no HTTPS).
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
