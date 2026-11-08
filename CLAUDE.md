# Claude Code Guideline: Zero-Cost Video-to-Khmer Dubbing Tool

Oct 29, 2026 · @Subscription Team

## How to use this guideline

Save this document as `CLAUDE.md` in the root of your project folder, then start Claude Code there. Claude Code reads that file at the start of every session, so it keeps the rules and the plan. Give it one phase prompt at a time from the last section.

To export it, use the doc's download option and choose Markdown.

## Project goal

Build a web tool where a user uploads a video in any spoken language and receives (1) a Khmer subtitle file (.srt) and (2) the same video with a Khmer voice track. The backend is Laravel. The AI processing runs in a separate FastAPI (Python) worker.

## Zero-cost rules (apply to every decision)

1. Use only free and open-source tools that run on the developer's own computer.
2. Never add a paid API or a service that needs a payment card. This includes the Claude API, OpenAI, Azure, Google Cloud and ElevenLabs.
3. Never add a dependency that needs an account or API key to work, except a free Hugging Face token if a model strictly requires one. Tell the developer first.
4. Everything must run offline after the one-time model downloads, except the Khmer voice in Stage 4 (see the stack table).
5. If a free option is too weak, keep it, document the limit in the README, and do not switch to a paid one.
6. Before adding any new package, state its license and confirm it is free for personal and student use. Docker Engine and Docker Desktop are allowed: they are free for personal, student and small-business use.

## Tech stack

Use exactly these tools. Do not substitute without asking the developer.

| Layer | Tool | Notes |
| --- | --- | --- |
| Web backend | Latest Laravel (13.x) on PHP 8.5, in Docker | Auth, uploads, queue, status, downloads |
| Database | MySQL | Table `video_jobs` |
| Queue | Laravel database queue | No Redis needed |
| Frontend | Blade + plain JavaScript (fetch polling) | No React for now |
| AI worker | Latest stable Python (3.14, or 3.13 if a package has no 3.14 build), latest FastAPI, Uvicorn, in Docker | One worker thread, jobs run one at a time |
| Audio and video | FFmpeg, pydub | Installed in the worker image with apt |
| Speech-to-text | faster-whisper | Model `small` by default, `medium` if the machine allows |
| Translation to Khmer | NLLB-200 distilled 600M (`facebook/nllb-200-distilled-600M`) via Hugging Face `transformers`, run in CTranslate2 with int8 weights (converted once into `HF_HOME`) | Free, local, Khmer code `khm_Khmr` |
| Khmer voice (main) | `edge-tts`, voices `km-KH-SreymomNeural` and `km-KH-PisethNeural` | Free but needs internet and is unofficial; isolate it behind one function |
| Khmer voice (offline fallback) | `facebook/mms-tts-khm` via `transformers` | Robotic but fully local; switch with an env flag |
| Remove the original speech (optional) | Demucs `htdemucs` (MIT) | Local; only with `separate=true`; about half the video length on CPU |

**Environment variables (Python `.env`)**

```bash
WORKER_TOKEN=change-me
WHISPER_MODEL=small
WHISPER_DEVICE=cpu        # cuda if an NVIDIA GPU exists
TTS_ENGINE=edge           # edge | mms
WORK_DIR=/data/jobs
MAX_VIDEO_MINUTES=15
ORIGINAL_VOLUME=0.15      # default for POST /jobs without original_volume, 0 to 1; the site always sends it
KHMER_SPACES=keep         # keep | remove the spaces NLLB puts between Khmer words
```

**Laravel `.env` additions**

```bash
DUBBER_URL=http://worker:8001
DUBBER_TOKEN=change-me
QUEUE_CONNECTION=database
```

The two tokens must match.

## Repository structure

Use one Git repository with two separate apps in two folders. Each app keeps its own dependencies, run command and .env file. Do not put FastAPI inside the Laravel folder, and do not move files between the two apps. Start Claude Code in the repository root so it can see both.

```text
khmer-dub/
  CLAUDE.md                  # this guideline
  README.md                  # setup and run instructions (keep updated)
  compose.yaml               # services: web, queue, scheduler, mysql, worker
  .gitignore
  web/                       # Laravel app
    Dockerfile
    .dockerignore
    .env.example
    app/Jobs/ProcessVideoJob.php
    app/Models/VideoJob.php
    app/Http/Controllers/VideoJobController.php
    database/migrations/
    resources/views/dub/index.blade.php
    routes/web.php
    config/services.php      # 'dubber' => url + token
  worker/                    # FastAPI app
    Dockerfile
    .dockerignore
    .env.example
    requirements.txt
    app.py                   # HTTP endpoints only
    pipeline.py              # runs the stages in order
    config.py                # reads environment variables
    stages/
      __init__.py
      audio.py               # FFmpeg extract
      transcribe.py          # faster-whisper
      translate.py           # NLLB-200
      tts.py                 # edge-tts or MMS
      mix.py                 # timing, mixing, merge, SRT
    tests/
```

**Setup commands Claude Code should run first**

```bash
mkdir khmer-dub && cd khmer-dub && git init
docker --version && docker compose version     # both must work; if not, ask the developer to install Docker
# create the Laravel project with the latest version, using a Composer container (no local PHP needed)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:latest create-project laravel/laravel web --ignore-platform-reqs --no-interaction
mkdir -p worker/stages worker/tests && touch worker/stages/__init__.py
# then write the Dockerfiles, compose.yaml and requirements.txt (see Docker setup), and start everything
docker compose up --build -d
docker compose exec web php artisan migrate
```

Add a root `.gitignore` that ignores `worker/jobs/`, `web/vendor/`, `web/node_modules/`, `web/storage/app/`, and every `.env` file. Commit the `.env.example` files.

## Docker setup

Everything runs in containers. The developer needs only Git and Docker (Docker Engine on Linux, or Docker Desktop with WSL 2 on Windows). No local PHP, Python or FFmpeg is installed.

**Containers**

| Service | Base image | Role | Published port |
| --- | --- | --- | --- |
| `web` | PHP 8.5 CLI with Composer, built from `web/Dockerfile` | Laravel site (`php artisan serve --host=0.0.0.0 --no-reload` with `PHP_CLI_SERVER_WORKERS=4`, so the video player does not hold up the site) | 8000 |
| `queue` | same image as `web` | `php artisan queue:work --timeout=3700` | none |
| `scheduler` | same image as `web` | `php artisan schedule:work` (runs `dub:cleanup` daily) | none |
| `mysql` | official `mysql` image, latest LTS tag | database | none |
| `worker` | official `python` slim image (3.14, or 3.13 if needed) plus FFmpeg | FastAPI worker | none |
| `mailpit` | `axllent/mailpit` (MIT) | catches the site's email (password reset links) | 8025 on 127.0.0.1 only |

**Rules**

1. Use one root `compose.yaml` (Compose v2, no `version:` key) and one network. Laravel reaches the worker at `http://worker:8001` and MySQL at host `mysql`.
2. Never publish the worker port to the host. Only the site on port 8000 is reachable from outside.
3. Use the latest stable versions at the time of building: the newest Laravel, the newest FastAPI, and the newest Python for which every package installs. Try Python 3.14 first. If a package such as `ctranslate2`, `sentencepiece` or `torch` has no build for it, use 3.13 and write the reason in the README. Check versions with `composer show` and `pip index versions <name>`.
4. After the first successful build, pin the resolved Python package versions in `worker/requirements.txt`, commit `web/composer.lock`, and pin the image tags, so the next build is repeatable.
5. Worker image: install FFmpeg with `apt`, install CPU-only PyTorch from `https://download.pytorch.org/whl/cpu` before the other packages to keep the image small, run as a non-root user, and start with `uvicorn app:app --host 0.0.0.0 --port 8001`.
6. Volumes: `mysql_data` for the database, `worker_jobs` mounted at `/data/jobs`, and `hf_cache` for `HF_HOME` (the Whisper and NLLB model files), so models download only once. Bind-mount `./web` into `web` and `queue` during development, which also shares uploads and results between them.
7. Add `GET /health` to the worker (no token, returns `{"ok": true}` and nothing else) and use it for the worker healthcheck. Use `mysqladmin ping` for MySQL. Make `web` and `queue` wait for a healthy `mysql`, and `queue` wait for a healthy `worker`.
8. Read settings from `env_file` entries. Never bake secrets into an image. Inside Compose the Laravel settings are `DB_HOST=mysql` and `DUBBER_URL=http://worker:8001`.
9. Add a `.dockerignore` to each app. Install `curl` in the `web` image so the API can be tested from inside the network.
10. Whisper `small` and NLLB-200 need about 6 to 8 GB of memory together. Tell the developer to give Docker at least 8 GB of RAM.
11. NVIDIA GPU support is optional and off by default. Add it only if the developer asks.
12. This setup is for development. A production image (nginx and PHP-FPM) is out of scope for the first version.

**Everyday commands**

```bash
docker compose up --build -d                  # start everything; open http://localhost:8000
docker compose exec web php artisan migrate   # create tables
docker compose exec web php artisan test      # Laravel tests
docker compose run --rm worker pytest         # worker tests
docker compose logs -f worker                 # worker log
docker compose down                           # stop; volumes (models, database) are kept
```

Run every worker script and test through `docker compose run --rm worker ...`, never with a local Python.

## API contract between Laravel and FastAPI

Both apps must follow this contract exactly. Every request to the worker carries the header `x-token` with the shared token. Keep the worker on `worker:8001`.

| Method and path | Sends | Returns |
| --- | --- | --- |
| `POST /jobs` | multipart: `file`, `gender` (`male` or `female`), `dub` (`true` or `false`), `review` (`true` or `false`, default `false`), `original_volume` (0 to 1, how loud the original sound plays under the Khmer voice; 0 = off, default `ORIGINAL_VOLUME`), `language` (the spoken language as a Whisper code; empty or left out = detect it), `separate` (`true` or `false`, default `false`: take the speech out of the original sound, keeping music and effects) | `{"job_id": "<hex>"}` |
| `GET /jobs/{id}` | nothing | `{"status", "progress", "message", "error", "language", "done", "total"}` |
| `GET /jobs/{id}/segments` | nothing | `{"segments": [{"id", "start", "end", "text", "km"}]}` while the job is in `review` or `done` |
| `PUT /jobs/{id}/segments` | JSON: `{"segments": [{"id", "km"}]}`, optional `gender` and `original_volume` | `{"status": "queued"}` after saving the Khmer and choices and resuming the job; lines left out keep their text, choices left out stay, and an empty line is dropped |
| `POST /jobs/{id}/reopen` | multipart: `file` (the original video) | `{"status": "review"}`, putting a `done` job back in review to make its results again; also accepted in `review`, so a refused save can be sent again |
| `POST /jobs/{id}/cancel` | nothing | `{"status": <status now>}`, stopping a queued or running job: `cancelled`, or `review` for a job past its review, once it has stopped; `queued` or `processing` while a running job gets to its next step |
| `DELETE /jobs/{id}` | nothing | `{"status": "deleted"}` after deleting a job that is not queued or running, with its folder |
| `GET /jobs/{id}/download/srt` | nothing | the .srt file |
| `GET /jobs/{id}/download/video` | nothing | the dubbed .mp4 file |
| `POST /cleanup` | form: `days` (number above 0, default 7) | `{"deleted": <count>}` after deleting finished job folders created more than `days` days ago; queued and running jobs are kept |

**Status values**: `queued`, `processing`, `review`, `done`, `failed`, `cancelled`. `review` means a job sent with `review=true` has stopped after translation, or a finished job was reopened, until its Khmer is saved; it holds no worker thread. `progress` is an integer from 0 to 100. On failure, `error` holds a short human-readable reason. `language` is the detected spoken language as a Whisper code (for example `en`), or null until the Khmer is ready (`review` or `done`); Laravel stores it in `video_jobs.language`.

**Progress milestones**: 5 extract audio, 15 transcribe, 45 translate, 50 waiting for review (only with `review=true`), 60 voice, 85 mix and merge (with `separate=true`: 85 remove the original speech, 92 mix and merge), 100 done. Inside the long steps the progress moves on, and `done` and `total` say how far the step is: seconds of the video while transcribing or removing the speech, lines while translating or making the voice. Otherwise they are null.

**Error cases the worker must handle with a clear message**: missing file, unsupported format, video longer than `MAX_VIDEO_MINUTES`, no speech found, FFmpeg not installed, model download failed, TTS service unreachable. Return HTTP 401 for a wrong token, 404 for an unknown job, 409 for a review call on a job that is not in review, a reopen of a job that cannot be edited, a cancel of a job that is not queued or running, or a delete of one that is, 422 for bad input. A restart puts a queued or running job back in line once, in its old order, going on from its last saved step (the voice once the Khmer is ready). A failure after the review, or a second restart of the same run, sends the job back to `review` with `error` set, so the edits are kept and saving retries it; before the review it fails. A job cancelled after its review also goes back to `review`, without an error.

## FastAPI worker specification

The worker runs five stages in order. Each stage is one file in `worker/stages/`, with one public function, type hints and a docstring. The data passed between stages is a list of segment dictionaries.

```python
# segment shape
{"id": 0, "start": 1.2, "end": 4.8, "text": "Hello", "km": "សួស្តី", "clip": "jobs/<id>/clips/0.wav"}
```

**Stage 1: `audio.py`**

- `extract_audio(video_path, wav_path)` runs FFmpeg to produce a mono 16 kHz WAV.
- Check the video length with `ffprobe` first and reject files longer than `MAX_VIDEO_MINUTES`.

**Stage 2: `transcribe.py`**

- `transcribe(wav_path)` returns `(segments, language_code)` using faster-whisper with `vad_filter=True`.
- Load the model once and reuse it. Use `int8` on CPU and `float16` on CUDA.
- Skip empty segments. Raise a clear error if no speech is found.
- Join a line to the next when the sentence clearly continues (the line ends in a comma or similar, or the next starts in lowercase), so it is translated whole. Never join lines more than 2.5 s apart or into a line longer than 12 s.

**Stage 3: `translate.py`**

- `translate(segments, src_lang)` fills `km` for every segment using NLLB-200 with target `khm_Khmr`.
- Keep a dictionary that maps Whisper language codes to NLLB codes (for example `en` to `eng_Latn`, `zh` to `zho_Hans`, `th` to `tha_Thai`, `ja` to `jpn_Jpan`, `ko` to `kor_Hang`, `fr` to `fra_Latn`, `vi` to `vie_Latn`). Raise a clear error for an unmapped language.
- If the source language is already Khmer (`km`), copy `text` into `km` and skip the model, but only for lines written in Khmer letters. Raise a clear error if none are.
- Raise a clear error if no translated line contains Khmer letters.
- Translate in batches of 8 to 16 segments to save time. Load the model once. Remove the spaces between Khmer words only with `KHMER_SPACES=remove`.
- Never call any online translation service.

**Stage 4: `tts.py`**

- `synth(text, out_path, gender)` hides the engine behind the `TTS_ENGINE` setting: `edge` uses `edge-tts` with `km-KH-SreymomNeural` (female) or `km-KH-PisethNeural` (male); `mms` uses `facebook/mms-tts-khm` locally.
- Add `synth_all(segments, folder, gender)` that writes one clip per segment and sets `clip`. Skip segments whose `km` is empty.
- Retry `edge-tts` twice on failure. If it still fails and `TTS_ENGINE=edge`, raise an error that tells the developer to switch to `mms`.
- Convert every clip to WAV 24 kHz mono before it leaves this stage, so Stage 5 handles one format.

**Stage 5: `mix.py`**

- `build_track(segments, total_ms, folder)` makes a silent track the length of the video and overlays each clip at its `start` time.
- Only speed clips up, never slow them. Use FFmpeg `atempo` with a maximum factor of 1.6 (`MAX_SPEED`). Allow a clip to run into the silence after its slot, but never past the next segment's start. Speed it up only when it does not fit before the next segment.
- `merge(video, dub_wav, out)` uses `-c:v copy` and AAC audio, so the video is not re-encoded. The original sound plays under the Khmer voice at the job's `original_volume`, fixed for the whole video, or with `separate` the sound without its speech (kept as `background.wav` for the next run). The Khmer subtitles go in as an MP4 text track that players can switch on.
- `write_srt(segments, path)` writes UTF-8 SRT with the Khmer text and the original timestamps.

**`pipeline.py`**

- `run(job_dir, video_path, gender, dub, progress)` calls the stages in order and reports the progress milestones from the API contract.
- If `dub` is false, stop after the SRT.

**`app.py`**

- Implements only the endpoints in the API contract. Use one `ThreadPoolExecutor(max_workers=1)`. Validate the token on every route. Keep job state in a dictionary, and also write `status.json` into each job folder so a restart does not lose finished jobs.

## Laravel app specification

Laravel stores uploads, queues the work, tracks progress, and serves results. It never runs AI code itself.

**Database table `video_jobs`**

| Column | Type | Note |
| --- | --- | --- |
| `id` | bigint | primary key |
| `user_id` | foreign key, nullable | owner; fill when login is added |
| `original_name` | string | uploaded file name |
| `path` | string | stored upload path |
| `gender` | string | `male` or `female`, default `female` |
| `dub` | boolean | default true |
| `review` | boolean | default false; stop for a person to edit the Khmer |
| `original_volume` | decimal(3,2) | default 0; original sound under the voice, 0 (off) to 1 |
| `spoken_language` | string, nullable | the spoken language chosen on the form, a Whisper code; null = detect it |
| `separate` | boolean | default false; take the original speech out of the sound under the voice |
| `status` | string | `queued`, `processing`, `review`, `done`, `failed`, `cancelled` |
| `progress` | tinyint | 0 to 100 |
| `message` | string, nullable | current step text |
| `step_done`, `step_total` | integer, nullable | how far the current step is, from the worker's `done` and `total` |
| `language` | string, nullable | detected language |
| `worker_job_id` | string, nullable | id returned by the worker |
| `srt_path`, `video_path` | string, nullable | saved results |
| `error` | text, nullable | failure reason |
| `cancel_requested` | boolean | default false; a person asked to stop the job and the worker has not stopped yet |

`users` also has `dub_options` (JSON, nullable): the user's latest choices on the upload form (`gender`, `dub`, `review`, `original_volume`, `separate`), saved as soon as one changes. The form starts from them. They stay after cleanup deletes the user's jobs.

**Files and responsibilities**

- `VideoJobController`: `index` (page and history), `store` (validate, save, dispatch), `saveOptions` (keep the form's choices), `status` (JSON), `cancel` (stop a waiting or running job), `destroy` (delete a stopped video for good, on the worker first), `download` (`srt` or `video`), `preview` (`video` with range requests, or the subtitles as `vtt`, for the page's player), `review` (edit page, for a job in review or a finished dubbed video), `saveReview` (send the edits, with the video again for a finished job, dispatch again).
- `ProcessVideoJob`: sends the upload to `POST /jobs`, polls `GET /jobs/{id}` every 4 seconds, updates the row, ends if the job waits for review (`saveReview` dispatches it again) or was cancelled, then downloads both results into `storage/app/results/{id}/`, replacing older ones only once both have arrived. Set `$timeout = 3600`, `$tries = 1` and `$deleteWhenMissingModels = true`. On any exception, set `status=failed` and store the message; if an older video exists, the job stays `done` with the message instead.
- Config in `config/services.php` under `dubber` (`url`, `token`). Read both from `.env`.

**Routes**

```php
GET  /dub                        index
POST /dub                        store
POST /dub/options                saveOptions (the form's choices, sent when one changes)
GET  /dub/{job}/status           status (JSON)
POST /dub/{job}/cancel           cancel (JSON, the job's status)
DELETE /dub/{job}                destroy
GET  /dub/{job}/review           review (page)
POST /dub/{job}/review           saveReview
GET  /dub/{job}/download/{kind}  download (kind = srt | video)
GET  /dub/{job}/preview/{kind}   preview (kind = video | vtt), inline for the player
```

**Validation**: video required, `mimes:mp4,mov,mkv,webm`, maximum 512000 KB, `gender` in `male,female`, `dub` boolean, `review` boolean, `original_volume` number from 0 to 1.

**UI (`dub/index.blade.php`)**: one page with a file input, a voice select, a dub checkbox, an original sound slider (0 to 100%, starts at Off, shown only when dubbing), a progress bar, a status message, the current step with how far it is, which unfolds into the list of all the job's steps when clicked, a cancel button with a confirmation dialog while the job can still be stopped, a delete button with a confirmation dialog on every stopped video, a video player with the Khmer subtitles and a close button that shows a dubbed result when done, a Play link on every dubbed video in the history, and download links that appear when done. Use plain `fetch` and `setTimeout` polling every 3 seconds. Show the interface in English or Khmer, one at a time, with a switch on every page; all Khmer text is in `web/lang/km.json`. Keep the page simple; do not add React, Tailwind builds or npm packages for the first version.

**Security basics**: add Laravel Breeze (free) for login in the last phase, then make `status`, `cancel`, `destroy`, `download` and `preview` check `job->user_id === auth()->id()`. Never expose the worker port outside the machine.

## Build phases and acceptance checks

Build in this order. Finish and test each phase before starting the next, and commit to Git after each one. A phase is done only when its check passes.

| Phase | Build | Done when | Est. hours |
| --- | --- | --- | --- |
| 0 | Repo layout, latest Laravel project, Dockerfiles, `compose.yaml`, `.env.example` files, `.gitignore`, README stub | `docker compose up --build` starts `web`, `queue`, `mysql` and `worker`, all healthy; `docker compose exec worker ffmpeg -version` and the Python imports work | 4 to 6 |
| 1 | Stage 1 and 2 (audio, transcribe) as a command-line script run in the worker container | A 1 minute test video prints timed segments that match the speech | 4 to 6 |
| 2 | Stage 3 (NLLB translation) | Khmer text for every segment; a Khmer speaker rates a 20 line sample as understandable | 4 to 6 |
| 3 | Stage 5 `write_srt` and subtitle-only pipeline | `khmer.srt` opens in VLC with the video and the timing looks right | 2 to 3 |
| 4 | Stage 4 and 5 (voice, timing, merge) | `dubbed.mp4` plays with Khmer speech in sync; no clip runs past the next one | 5 to 8 |
| 5 | FastAPI app, `/health` and job status | The contract calls work from the `web` container with `curl`, and `docker compose run --rm worker pytest` passes | 3 to 4 |
| 6 | Laravel: migration, controller, queue job, Blade page | Upload at `http://localhost:8000`, see the progress bar move, download both files | 6 to 10 |
| 7 | Login (Breeze), ownership checks, cleanup command, error messages, README | A second user cannot open the first user's job; old files are deleted; a fresh clone starts with only the README steps | 5 to 8 |

The hours are estimates for working with Claude Code. Phase 4 and the Khmer quality check in Phase 2 are the ones most likely to run over.

**Order of risk**: prove the Khmer translation quality in Phase 2 before building the rest. If NLLB output is too weak for the developer's goals, stop and report it instead of adding a paid service.

## Coding rules and limits

**Always**

- Work on one phase at a time. At the start of each phase, say which phase you are on and what you will build.
- Write small functions with type hints (Python) and docblocks (PHP). Keep stage files independent so each can be run and tested alone.
- Add a pytest test for every stage using a short sample file. Run the tests before saying a phase is done.
- Read all settings from `.env`. Provide `.env.example` files with no real secrets.
- Use `subprocess.run([...], check=True)` with a list of arguments for FFmpeg, never a shell string built from user input.
- Save every uploaded file under a random name, never the user's file name.
- Write clear error messages the user can act on, and log technical details on the server.
- Update `README.md` with any new setup step or limit.
- Ask the developer when a requirement is unclear, instead of guessing.

**Never**

- Never add a paid service, an API key, or a card requirement (see the zero-cost rules).
- Never commit `.env`, model files, uploads or job folders.
- Never expose the FastAPI port to the internet or skip the token check.
- Never delete or overwrite the developer's files outside the project folder.
- Never re-encode the video when `-c:v copy` works.
- Never claim the Khmer translation or voice is correct. Say it needs a Khmer speaker to review.
- Never run the Laravel queue and the worker with different tokens.

**Known limits to document in the README**

- Free translation (NLLB) is less natural than a paid LLM and can mistranslate names and numbers.
- `edge-tts` is unofficial and needs internet. It may change or stop working. `mms` is the offline fallback.
- CPU transcription is slow, roughly a few minutes for a 10 minute video with the `small` model.
- Only use videos the user owns or may translate; label dubbed videos as AI-generated voice.

**Definition of done for the whole project**

- [ ] A user can log in, upload a video, watch progress, and download a Khmer `.srt` and a dubbed `.mp4`.
- [ ] The whole stack starts with docker compose up --build on one machine, with no paid service.
- [ ] All tests pass and the README explains install, run, and known limits.
- [ ] Jobs that fail show a clear message, and old files are cleaned up.

## Prompts to paste into Claude Code

Paste one prompt at a time, and wait until the phase passes its check before sending the next. Replace the text in angle brackets with your own details.

**Start of the first session**

```text
Read CLAUDE.md fully. Summarize the project, the zero-cost rules and the 8 phases in 10 lines so I can confirm you understood. Do not write code yet.
```

**Phase 0: setup**

```text
Do Phase 0 from CLAUDE.md. Create the repo layout and the Laravel project in web/ using the latest Laravel. Write the Dockerfiles for web and worker, compose.yaml (web, queue, mysql, worker), .env.example files, .dockerignore files, .gitignore and a README stub, following the Docker setup section and using the latest stable versions. Run docker compose up --build and show me that all four containers are healthy and that ffmpeg and the Python imports work inside the worker container. Tell me anything I must install myself. Commit when done.
```

**Phase 1: audio and transcription**

```text
Do Phase 1. Write worker/stages/audio.py and worker/stages/transcribe.py exactly as specified, plus a small script worker/try_transcribe.py that takes a video path and prints the timed segments and detected language. Add pytest tests. Then tell me how to run it on my test video: <path to a 1 minute video>.
```

**Phase 2: Khmer translation**

```text
Do Phase 2. Write worker/stages/translate.py with NLLB-200 as specified, including the language code map and the Khmer-to-Khmer shortcut. Add worker/try_translate.py that prints the original and Khmer text side by side for the first 20 segments of my test video. Do not add any online service.
```

**Phase 3: subtitles only**

```text
Do Phase 3. Add write_srt in worker/stages/mix.py and a pipeline.run that supports dub=false. Create worker/try_pipeline.py that makes khmer.srt from a video. Tell me how to check it in VLC.
```

**Phase 4: Khmer voice and merge**

```text
Do Phase 4. Write worker/stages/tts.py with the edge and mms engines behind TTS_ENGINE, and finish worker/stages/mix.py with build_track and merge using the speed-up rules in CLAUDE.md. Extend pipeline.run for dub=true. Test on my test video and report the longest speed-up factor used and any clip that overlaps the next one.
```

**Phase 5: FastAPI service**

```text
Do Phase 5. Write worker/app.py and worker/config.py following the API contract exactly, plus GET /health, with token checks, one worker thread, MAX_VIDEO_MINUTES, and status.json per job. The worker port is not published, so give me the curl commands to run from the web container (docker compose exec web curl ...) to test upload, status and download. Run the worker tests inside the container and show the result.
```

**Phase 6: Laravel app**

```text
Do Phase 6. In web/, add the migration, VideoJob model, config/services.php entry, VideoJobController, ProcessVideoJob, routes and the dub/index.blade.php page exactly as specified. Use the database queue and the queue container. Tell me the docker compose commands to start everything and run migrations, that the site is at http://localhost:8000, and how to raise the PHP upload limits in the web image.
```

**Phase 7: login, cleanup, finish**

```text
Do Phase 7. Add Laravel Breeze login, ownership checks on status and download, a scheduled artisan command that deletes uploads, results and worker job folders older than 7 days, clear error messages, and finish README.md with install steps, run steps and the known limits. Then check every box in the Definition of done and list any that are not met.
```

**When something breaks**

```text
This step fails: <phase and step>. Error text: <paste the full error>. Relevant file: <path>. Find the cause, fix it with the smallest change, and run the tests again. Do not change anything outside this problem.
```

**Quality check after Phase 4**

```text
List every place where the free tools limit quality (translation, voice, timing). For each one, suggest the best free improvement, and state its cost in time. Do not suggest any paid service.
```
