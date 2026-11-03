# Khmer Dub

Upload a video in any spoken language and get back a Khmer subtitle file (`.srt`) and the
same video with a Khmer voice track. Zero cost: every tool is free and open source and runs
on your own machine. See `CLAUDE.md` for the full specification and build phases.

- `web/`: Laravel 13 site (uploads, queue, progress, downloads)
- `worker/`: FastAPI worker (FFmpeg, faster-whisper, NLLB-200, edge-tts / MMS-TTS)

> Status: Phase 6 (upload a video at http://localhost:8000, watch its progress, download the
> Khmer `.srt` and dubbed `.mp4`). There is no login yet, so everyone who can open the site
> sees every job.

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
docker compose ps                             # wait until all four services are "healthy"
docker compose exec web php artisan migrate   # create tables
```

Open http://localhost:8000 (it goes to the dubbing page at `/dub`). `APP_KEY` and `web/vendor/`
are created automatically on the first start.

## Everyday commands

```bash
docker compose up --build -d                  # start everything
docker compose exec web php artisan migrate   # create tables
docker compose exec web php artisan test      # Laravel tests
docker compose run --rm worker pytest         # worker tests
docker compose logs -f worker                 # worker log
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
- A clip may run up to 25 % past its line's slot, but never past the next line's start. A longer
  clip is sped up with FFmpeg `atempo`, at most x1.6, and never slowed down. If it still does not
  fit, its end is cut with a short fade.
- `dubbed.mp4` copies the picture (`-c:v copy`) and adds the Khmer track as AAC, tagged as an
  AI-generated voice. MP4 cannot hold some WebM codecs (VP8, for example). Those videos are
  re-encoded to H.264 instead, which is slower.

Segment times are fitted to the speech. Whisper's own times often start a line early (by up to
2 s on the test clip) or let it run into the next one, so `stages/transcribe.py` does three things:
it asks Whisper for word timestamps, splits a segment wherever two words are 1 s or more apart,
and moves each edge onto the speech found by the Silero VAD (bundled with faster-whisper, MIT).

Translation runs locally with NLLB-200 (`facebook/nllb-200-distilled-600M`, target `khm_Khmr`),
12 segments per batch. The Whisper-to-NLLB language map is in `worker/stages/translate.py`.
Khmer videos skip the model. Latin, Breton and Hawaiian are not supported, because Whisper can
hear them but NLLB-200 cannot translate them.

## Worker API

`worker/app.py` follows the API contract in `CLAUDE.md`. Every route except `GET /health`
needs the header `x-token` equal to `WORKER_TOKEN` (worker/.env), which must equal
`DUBBER_TOKEN` (web/.env). An empty `WORKER_TOKEN` refuses every call.

| Call | Answer |
| --- | --- |
| `POST /jobs` (multipart `file`, `gender` = `female`/`male`, `dub` = `true`/`false`) | `{"job_id": "<32 hex>"}` |
| `GET /jobs/{id}` | `{"status", "progress", "message", "error", "language"}` |
| `GET /jobs/{id}/download/srt` | `khmer.srt` |
| `GET /jobs/{id}/download/video` | `dubbed.mp4` (only for `dub=true`) |
| `GET /health` | `{"ok": true}` |

- 401 for a wrong or missing token, 404 for an unknown job or a result that is not ready, 422 for
  bad input. Every error has a readable `detail`. An upload is checked with ffprobe right away,
  so a missing file, an unsupported or unreadable format, a video without sound, or one longer
  than `MAX_VIDEO_MINUTES` is refused with 422 before it is queued. If FFmpeg itself is missing,
  the answer is 500.
- `language` is the detected spoken language as a Whisper code (for example `en`), or `null`
  until the job is done. `error` is `null` unless the job failed.
- `gender` defaults to `female` and `dub` to `true` when they are left out.
- Jobs run one at a time on one worker thread, in upload order. Later uploads wait as `queued`.
- Each job lives in `/data/jobs/<id>/` (the `worker_jobs` volume) with a `status.json`. After a
  restart, finished jobs can still be downloaded. A job that was queued or running when the
  worker stopped is marked `failed` and must be uploaded again.
- When a job succeeds, the worker deletes its copy of the upload and its working files (audio,
  voice clips). It keeps `khmer.srt`, `dubbed.mp4`, `segments.json` and `status.json`.
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
Piseth), a "dubbed video" checkbox (unticked gives Khmer subtitles only), a progress bar that
updates every 3 seconds, download links when the job is done, and the last 20 jobs.

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

A failed job shows the reason: the worker's own message (no speech, too long, voice service
unreachable, and so on), or a hint when the worker is down or the tokens differ. A job stops
after one hour (`$timeout = 3600`) and is not retried (`$tries = 1`).

`queue:work` keeps the code it started with. After changing PHP code or config, run
`docker compose restart queue`. The `web` service picks up changes on the next request.

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
| `web`    | `php:8.5.12-cli-trixie` + Composer 2.10 | `php artisan serve`   | 8000 |
| `queue`  | same as `web`                 | `php artisan queue:work --timeout=3700` | none |
| `mysql`  | `mysql:9.7.4` (LTS)           | database                          | none |
| `worker` | `python:3.14.10-slim-trixie` + FFmpeg 7.1 | FastAPI on `worker:8001` | none |

On a new database the `queue` service waits, printing "waiting for php artisan migrate",
until you run the migrate command. Then it becomes healthy.

The worker port is never published; only `web` and `queue` reach it on the Compose network.
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
- The dubbed video has only the Khmer voice. The original music and sound effects are not kept.
- Khmer is often longer than the original. A short line can lose the end of its speech even at
  x1.6 (0.9 s of one 0.5 s line on the test clip), although the silence after it is free.
- Making the voice takes about 2 seconds per line with edge-tts.
- CPU transcription is slow, roughly a few minutes for a 10 minute video with the `small` model.
- The worker runs one job at a time, so an upload can wait in the queue behind a long video.
  Restarting the worker fails the job that was running.
- The page cannot show how far a large upload has got; it shows "Uploading" until the server has
  the whole file. `php artisan serve` is a development server, and a slow upload can hold up the
  page's other requests.
- The Khmer labels and step messages on the page were written by AI and need review by a Khmer
  speaker.
- There is no login yet (Phase 7), so everyone who can open the site sees and downloads every job.
- Whisper `small` sometimes mishears words (for example "timed" as "time"), and over music or
  noise it can invent short phrases such as "Thank you." `WHISPER_MODEL=medium` is more accurate
  but needs about twice the memory and time.
- Speech detection can miss quiet or crackly speech (old recordings, distant voices). Then a line's
  end can be cut a little short, by up to about 0.5 s.
- Subtitles keep the exact speech times. A very short line (0.5 s on the test clip) can disappear
  before it is read, and a long one is shown as one line that the player wraps.
- NLLB-200 and MMS-TTS weights are licensed for non-commercial use only.
- Only use videos you own or may translate; label dubbed videos as AI-generated voice.
