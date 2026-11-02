# Khmer Dub

Upload a video in any spoken language and get back a Khmer subtitle file (`.srt`) and the
same video with a Khmer voice track. Zero cost: every tool is free and open source and runs
on your own machine. See `CLAUDE.md` for the full specification and build phases.

- `web/`: Laravel 13 site (uploads, queue, progress, downloads)
- `worker/`: FastAPI worker (FFmpeg, faster-whisper, NLLB-200, edge-tts / MMS-TTS)

> Status: Phase 4 (Khmer subtitles and a Khmer dubbed video, as command-line scripts).
> The worker API and the upload page are not built yet.

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

Open http://localhost:8000. `APP_KEY` and `web/vendor/` are created automatically on the first start.

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

PHP upload limits (512 MB) are set in `web/docker/php.ini`.

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
- Whisper `small` sometimes mishears words (for example "timed" as "time"), and over music or
  noise it can invent short phrases such as "Thank you." `WHISPER_MODEL=medium` is more accurate
  but needs about twice the memory and time.
- Speech detection can miss quiet or crackly speech (old recordings, distant voices). Then a line's
  end can be cut a little short, by up to about 0.5 s.
- Subtitles keep the exact speech times. A very short line (0.5 s on the test clip) can disappear
  before it is read, and a long one is shown as one line that the player wraps.
- NLLB-200 and MMS-TTS weights are licensed for non-commercial use only.
- Only use videos you own or may translate; label dubbed videos as AI-generated voice.
