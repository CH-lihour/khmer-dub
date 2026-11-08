"""HTTP endpoints of the dubbing worker, exactly as in the API contract of CLAUDE.md.

    POST /jobs                       multipart file, gender, dub, review, original_volume -> {"job_id": "<hex>"}
    GET  /jobs/{id}                  -> {"status", "progress", "message", "error", "language", "done", "total"}
    GET  /jobs/{id}/segments         -> {"segments": [...]}, while the job is in review or done
    PUT  /jobs/{id}/segments         JSON {"segments": [{"id", "km"}], "gender", "original_volume"}
                                     -> saves the Khmer and the choices, and resumes
    POST /jobs/{id}/reopen           multipart file (the original video) -> puts a done job back in review
    POST /jobs/{id}/cancel           -> {"status": ...}, stops a queued or running job
    DELETE /jobs/{id}                -> {"status": "deleted"}, deletes a stopped job and its folder
    GET  /jobs/{id}/download/srt     -> khmer.srt
    GET  /jobs/{id}/download/video   -> dubbed.mp4
    POST /cleanup                    form days (default 7) -> {"deleted": <count>}
    GET  /health                     -> {"ok": true}, the only route without the x-token header

Jobs run one at a time on a single worker thread. Each job has a folder WORK_DIR/<id>/ with a
status.json next to its results, so finished jobs survive a restart of the worker. A job sent with
review=true stops after translation with status "review", holding no thread, until its Khmer is saved.
A finished job can come back to "review" with POST /jobs/{id}/reopen, to make its results again from
edited Khmer. The upload was tidied away when it finished, so Laravel sends the video again.
A job in line is cancelled at once, and a running one at its next progress report. It ends as
"cancelled", or, past its review, waits in "review" again so the reviewer's work is kept.
"""

import hmac
import json
import logging
import os
import re
import secrets
import shutil
import threading
from collections.abc import AsyncIterator, Awaitable, Callable
from concurrent.futures import Future, ThreadPoolExecutor
from contextlib import asynccontextmanager
from datetime import UTC, datetime, timedelta
from pathlib import Path
from typing import Annotated

from fastapi import FastAPI, File, Form, HTTPException, Request, Response, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel, Field

import config
import pipeline
from stages import StageError, has_other_script_only
from stages.audio import check_video, extract_audio
from stages.translate import WHISPER_TO_NLLB
from stages.tts import VOICES

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("worker")

FORMATS = (".mp4", ".mov", ".mkv", ".webm")
DUB_VALUES = {"true": True, "1": True, "false": False, "0": False}
SPOKEN_LANGUAGES = {*WHISPER_TO_NLLB, "km"}  # what a user may choose: languages NLLB-200 can translate, and Khmer
PUBLIC_FIELDS = ("status", "progress", "message", "error", "language", "done", "total")
JOB_ID = re.compile(r"[0-9a-f]{32}")
WRONG_TOKEN = "Wrong or missing x-token. It must equal WORKER_TOKEN in worker/.env and DUBBER_TOKEN in web/.env."
UNEXPECTED = "Something went wrong while processing the video. The worker log has the details."
RESTARTED = "The worker restarted before this job finished. Please upload the video again."
RESTARTED_REVIEW = "The worker restarted before the results were ready."
REVIEW_PROGRESS = 50  # between the translate (45) and voice (60) milestones
REVIEW_MESSAGE = "Waiting for your review"
NOT_IN_REVIEW = "This job is not waiting for review."
NOT_EDITABLE = "This job cannot be edited now. Only a finished job or one waiting for review can."
NO_LINES = "This job has no saved lines to edit. Please upload the video again."
NOT_RUNNING = "This job is not queued or running, so there is nothing to cancel."
STILL_RUNNING = "This job is still queued or running. Cancel it first."
MAX_LINE_CHARS = 2000


class EditedLine(BaseModel):
    """One reviewed line: the segment's id and its Khmer text. Empty text drops the line."""

    id: int
    km: str = Field(max_length=MAX_LINE_CHARS)


class Edits(BaseModel):
    """The body of PUT /jobs/{id}/segments. Lines left out keep their text; choices left out stay as they are."""

    segments: list[EditedLine]
    gender: str | None = None
    original_volume: float | None = Field(default=None, ge=0, le=1)


class Cancelled(Exception):
    """Raised from a job's progress report to stop the pipeline there."""


class JobStore:
    """Job state in a dictionary, mirrored to WORK_DIR/<id>/status.json, and the one worker thread."""

    def __init__(self, work_dir: Path) -> None:
        self.work_dir = work_dir
        self._jobs: dict[str, dict] = {}
        self._lock = threading.Lock()
        self._executor = ThreadPoolExecutor(max_workers=1, thread_name_prefix="job")
        self._futures: dict[str, Future] = {}  # what is in line or running on the thread, by job
        self._cancelling: set[str] = set()  # running jobs to stop at their next progress report
        self._load()

    def new_folder(self) -> tuple[str, Path]:
        """Make an empty folder for a new job and return its random id and path."""
        job_id = secrets.token_hex(16)
        folder = self.work_dir / job_id
        folder.mkdir(parents=True)
        return job_id, folder

    def submit(self, job_id: str, upload: str, gender: str, dub: bool, review: bool = False,
               original_volume: float | None = None, spoken: str | None = None, separate: bool = False) -> None:
        """Record a new job as queued and put it in line for the worker thread.

        `spoken` is the spoken language the user chose (None: detect it). "language" stays null
        until the Khmer is ready, as the API contract says.
        """
        job = {"status": "queued", "progress": 0, "message": "Waiting in the queue", "error": None,
               "language": None, "upload": upload, "gender": gender, "dub": dub, "review": review,
               "original_volume": original_volume, "spoken": spoken, "separate": separate, "created": _now()}
        with self._lock:
            self._jobs[job_id] = job
        self.update(job_id)
        self._futures[job_id] = self._executor.submit(self._run, job_id)

    def get(self, job_id: str) -> dict | None:
        """Return a copy of the job's state, or None for an unknown id."""
        with self._lock:
            job = self._jobs.get(job_id)
            return dict(job) if job else None

    def update(self, job_id: str, **fields: object) -> None:
        """Change some fields of a job and rewrite its status.json."""
        with self._lock:
            self._set(job_id, **fields)

    def resume(self, job_id: str, edits: Edits) -> None:
        """Save the reviewed Khmer and choices of a job in review and put it in line to make the results.

        Answers 422 for a line id the job does not have, an unknown voice, or if every line would be
        empty, and 409 if the job is not (or no longer) waiting for review, so saving twice cannot
        run it twice.
        """
        if edits.gender is not None and edits.gender not in VOICES:
            raise HTTPException(422, "gender must be female or male.")
        folder = self.work_dir / job_id
        segments = pipeline.load_segments(folder)
        changed = {line.id: line.km.strip() for line in edits.segments}
        unknown = changed.keys() - {seg["id"] for seg in segments}
        if unknown:
            raise HTTPException(422, f"These lines are not part of this job: {sorted(unknown)}.")
        for seg in segments:
            seg["km"] = changed.get(seg["id"], seg["km"])
        if not any(seg["km"] for seg in segments):
            raise HTTPException(422, "Every line is empty. Keep the Khmer text of at least one line.")
        foreign = [seg["id"] + 1 for seg in segments if has_other_script_only(seg["km"])]
        if foreign:
            raise HTTPException(422, f"These lines have no Khmer text: {foreign}. Write them in Khmer, or empty them.")

        with self._lock:
            if self._jobs[job_id]["status"] != "review":
                raise HTTPException(409, NOT_IN_REVIEW)
            pipeline.save_segments(folder, segments)
            choices = {"gender": edits.gender, "original_volume": edits.original_volume}
            self._set(job_id, status="queued", message="Waiting in the queue", error=None, restarts=0,
                      **{key: value for key, value in choices.items() if value is not None})
        self._futures[job_id] = self._executor.submit(self._finish, job_id)

    def cancel(self, job_id: str) -> str:
        """Stop a queued or running job and return its status now. Answers 409 for any other job.

        A job still in line stops at once. A running job stays "processing" until its next progress
        report, so the answer does not always mean it has stopped.
        """
        with self._lock:
            job = self._jobs[job_id]
            if job["status"] not in ("queued", "processing"):
                raise HTTPException(409, NOT_RUNNING)
            future = self._futures.get(job_id)
            if future is None or not future.cancel():
                self._cancelling.add(job_id)
                return job["status"]
        self._stopped(job_id)
        return self.get(job_id)["status"]

    def delete(self, job_id: str) -> None:
        """Forget a stopped job and delete its folder. Answers 409 for a queued or running job."""
        with self._lock:
            if self._jobs[job_id]["status"] in ("queued", "processing"):
                raise HTTPException(409, STILL_RUNNING)
            del self._jobs[job_id]
        shutil.rmtree(self.work_dir / job_id, ignore_errors=True)
        log.info("Job %s deleted", job_id)

    def cleanup(self, days: float) -> list[str]:
        """Delete the folders of finished jobs created more than `days` days ago; return their ids.

        Queued and running jobs are never deleted. A folder without a known job (no readable
        status.json) is dated by its last change, so an upload still being saved is kept.
        """
        cutoff = datetime.now(UTC) - timedelta(days=days)
        deleted = []
        for folder in sorted(self.work_dir.iterdir()):
            if not (folder.is_dir() and JOB_ID.fullmatch(folder.name)):
                continue
            with self._lock:
                job = self._jobs.get(folder.name)
                if (job and job.get("status") in ("queued", "processing")) or _created(folder, job) > cutoff:
                    continue
                self._jobs.pop(folder.name, None)
            shutil.rmtree(folder, ignore_errors=True)
            deleted.append(folder.name)
        return deleted

    def shutdown(self) -> None:
        """Drop the jobs still waiting; a running one finishes in the background."""
        self._executor.shutdown(wait=False, cancel_futures=True)

    def _set(self, job_id: str, **fields: object) -> None:
        """Change a job's fields and rewrite its status.json. The caller holds the lock."""
        job = self._jobs[job_id] = {**self._jobs[job_id], **fields, "updated": _now()}
        _write_json(self.work_dir / job_id / "status.json", job)

    def _run(self, job_id: str) -> None:
        """Start a new job on the worker thread: all of it, or up to the review of its Khmer."""
        job, folder = self.get(job_id), self.work_dir / job_id
        video, progress = folder / job["upload"], self._reporter(job_id)
        log.info("Job %s started (voice %s, dub %s, review %s)", job_id, job["gender"], job["dub"], job.get("review"))

        def work() -> None:
            if job.get("review"):
                prepared = pipeline.prepare(folder, video, progress, job.get("spoken"))
                self.update(job_id, status="review", progress=REVIEW_PROGRESS, message=REVIEW_MESSAGE, done=None, total=None,
                            restarts=0, **prepared)
            else:
                # Translated before a restart: "prepared" lets it go on from the voice.
                result = pipeline.run(folder, video, job["gender"], job["dub"], progress, job.get("original_volume"),
                                      prepared=job.get("prepared"), on_prepared=lambda done: self.update(job_id, prepared=done),
                                      language=job.get("spoken"), separate=job.get("separate", False))
                self._done(job_id, job["upload"], result)

        self._attempt(job_id, work)

    def _finish(self, job_id: str) -> None:
        """Make the results of a reviewed job on the worker thread."""
        job, folder = self.get(job_id), self.work_dir / job_id

        def work() -> None:
            result = pipeline.finish(folder, folder / job["upload"], job["gender"], job["dub"], job["duration"],
                                     self._reporter(job_id), job.get("original_volume"), separate=job.get("separate", False))  # .get: older status.json
            self._done(job_id, job["upload"], {**result, "language": job["language"]})

        # A failure here (the voice service being down, say) must not lose the reviewer's work:
        # the job waits in review again, with the error, and saving retries it.
        self._attempt(job_id, work, status="review", progress=REVIEW_PROGRESS, message=REVIEW_MESSAGE)

    def _reporter(self, job_id: str) -> Callable[..., None]:
        """A pipeline progress callback that marks the job as processing."""
        self.update(job_id, status="processing", message="Starting")

        def progress(percent: int, message: str, done: int | None = None, total: int | None = None) -> None:
            if job_id in self._cancelling:
                raise Cancelled
            self.update(job_id, progress=percent, message=message, done=done, total=total)

        return progress

    def _attempt(self, job_id: str, work: Callable[[], None], **on_failure: object) -> None:
        """Run `work`. A failure is recorded as the job's error, with `on_failure` or else status failed."""
        failed = on_failure or {"status": "failed", "message": "Failed"}
        try:
            work()
        except Cancelled:
            self._stopped(job_id)
        except StageError as exc:
            log.warning("Job %s failed: %s", job_id, exc)
            self.update(job_id, **failed, error=str(exc))
        except Exception:
            log.exception("Job %s crashed", job_id)
            self.update(job_id, **failed, error=UNEXPECTED)
        finally:
            self._cancelling.discard(job_id)
            self._futures.pop(job_id, None)

    def _stopped(self, job_id: str) -> None:
        """Record that a job was cancelled. Past its review it waits there again, keeping the edits."""
        job = self.get(job_id)
        log.info("Job %s cancelled", job_id)
        if "duration" in job:
            self.update(job_id, status="review", progress=REVIEW_PROGRESS, message=REVIEW_MESSAGE, error=None, done=None, total=None)
        else:
            _tidy(self.work_dir / job_id, job["upload"])
            self.update(job_id, status="cancelled", message="Cancelled", done=None, total=None)

    def _done(self, job_id: str, upload: str, result: dict) -> None:
        """Tidy the job's folder and record its results."""
        _tidy(self.work_dir / job_id, upload)
        video = Path(result["video"]).name if result["video"] else None
        self.update(job_id, status="done", progress=100, message="Done", error=None, done=None, total=None, restarts=0,
                    language=result["language"], srt=Path(result["srt"]).name, video=video)
        log.info("Job %s done", job_id)

    def _load(self) -> None:
        """Read the status.json of every job folder, and put the jobs a restart interrupted in line again."""
        self.work_dir.mkdir(parents=True, exist_ok=True)
        interrupted = []
        for status_file in self.work_dir.glob("*/status.json"):
            job_id = status_file.parent.name
            if not JOB_ID.fullmatch(job_id):
                continue
            try:
                self._jobs[job_id] = json.loads(status_file.read_text(encoding="utf-8"))
            except (OSError, ValueError):
                log.warning("Skipping unreadable %s", status_file)
                continue
            if self._jobs[job_id].get("status") in ("queued", "processing"):
                interrupted.append(job_id)
        for job_id in sorted(interrupted, key=lambda job_id: self._jobs[job_id].get("created", "")):
            self._restart(job_id)
        log.info("Loaded %d jobs from %s", len(self._jobs), self.work_dir)

    def _restart(self, job_id: str) -> None:
        """Put a job the worker stopped in the middle of in line again, in its old place.

        It goes on from the last saved step: the voice once the Khmer is ready, or the start. Only
        once: a job running when the worker stopped twice may be what stops it (out of memory, say),
        so it then fails, or waits in review again with the reviewer's work, as before.
        """
        job = self._jobs[job_id]
        reviewed = "duration" in job  # past its review: keep the reviewer's work whatever happens
        upload = job.get("upload")
        if job.get("restarts", 0) >= 1 or not upload or not (self.work_dir / job_id / upload).is_file():
            if reviewed:
                self.update(job_id, status="review", progress=REVIEW_PROGRESS, message=REVIEW_MESSAGE, error=RESTARTED_REVIEW,
                            done=None, total=None)
            else:
                self.update(job_id, status="failed", message="Failed", error=RESTARTED, done=None, total=None)
            return
        log.info("Job %s was interrupted by a restart; it goes on", job_id)
        self.update(job_id, status="queued", message="Waiting in the queue", error=None, done=None, total=None,
                    restarts=job.get("restarts", 0) + 1)
        self._futures[job_id] = self._executor.submit(self._finish if reviewed else self._run, job_id)


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    """Load the saved jobs and start the worker thread; stop it on shutdown."""
    if not config.WORKER_TOKEN:
        log.error("WORKER_TOKEN is empty in worker/.env, so every request except /health is refused")
    elif config.WORKER_TOKEN == "change-me":
        log.warning("WORKER_TOKEN is still change-me; set the same random value in worker/.env and web/.env")
    app.state.jobs = JobStore(config.WORK_DIR)
    yield
    app.state.jobs.shutdown()


# No /docs or /openapi.json: the worker serves only the routes of the contract.
app = FastAPI(title="Khmer dub worker", docs_url=None, redoc_url=None, openapi_url=None, lifespan=lifespan)


@app.middleware("http")
async def require_token(request: Request, call_next: Callable[[Request], Awaitable[Response]]) -> Response:
    """Answer 401 to every request except /health without the right x-token, before any upload is read."""
    if request.url.path != "/health" and not _token_ok(request.headers.get("x-token", "")):
        log.warning("Refused %s %s: wrong or missing x-token", request.method, request.url.path)
        return JSONResponse({"detail": WRONG_TOKEN}, status_code=401)
    return await call_next(request)


@app.exception_handler(RequestValidationError)
async def bad_input(request: Request, exc: RequestValidationError) -> JSONResponse:
    """Answer 422 with one readable sentence instead of FastAPI's list of errors."""
    problems = "; ".join(f"{error['loc'][-1]}: {error['msg']}" for error in exc.errors())
    return JSONResponse({"detail": f"Bad input. {problems}"}, status_code=422)


@app.get("/health")
def health() -> dict[str, bool]:
    """Liveness probe for the Docker healthcheck. Needs no token."""
    return {"ok": True}


@app.post("/jobs")
def create_job(
    request: Request,
    file: Annotated[UploadFile | None, File()] = None,
    gender: Annotated[str, Form()] = "female",
    dub: Annotated[str, Form()] = "true",
    review: Annotated[str, Form()] = "false",
    original_volume: Annotated[float | None, Form(ge=0, le=1)] = None,
    language: Annotated[str, Form()] = "",
    separate: Annotated[str, Form()] = "false",
) -> dict[str, str]:
    """Save the uploaded video, check it, queue it, and return {"job_id": "<hex>"}.

    `gender` is female or male (default female), `dub` is true or false (default true), and
    `review` is true to stop after translation so a person can edit the Khmer (default false).
    `original_volume` is how loud the original sound plays under the voice, 0 to 1 (default
    ORIGINAL_VOLUME). `language` is the spoken language as a Whisper code (empty: detect it), and
    `separate` is true to take the speech out of the original sound, keeping music and effects
    (default false). Answers 422 with a clear `detail` for a missing file, an unsupported or
    unreadable format, a video without sound or one longer than MAX_VIDEO_MINUTES.
    """
    gender, dub, review = gender.strip().lower(), dub.strip().lower(), review.strip().lower()
    language, separate = language.strip().lower(), separate.strip().lower()
    if language and language not in SPOKEN_LANGUAGES:
        raise HTTPException(422, "language must be a Whisper language code that can be translated to Khmer, or empty.")
    if separate not in DUB_VALUES:
        raise HTTPException(422, "separate must be true or false.")
    if file is None or not file.filename:
        raise HTTPException(422, "No video was sent. Attach it as the multipart field 'file'.")
    extension = Path(file.filename).suffix.lower()
    if extension not in FORMATS:
        raise HTTPException(422, f"Unsupported file type '{extension or 'none'}'. Upload an MP4, MOV, MKV or WebM video.")
    if gender not in VOICES:
        raise HTTPException(422, "gender must be female or male.")
    if dub not in DUB_VALUES:
        raise HTTPException(422, "dub must be true or false.")
    if review not in DUB_VALUES:
        raise HTTPException(422, "review must be true or false.")

    jobs: JobStore = request.app.state.jobs
    job_id, folder = jobs.new_folder()
    upload = folder / f"input{extension}"  # never the user's file name
    try:
        _save(file, upload)
        check_video(upload)
    except StageError as exc:
        shutil.rmtree(folder, ignore_errors=True)
        ffmpeg_missing = isinstance(exc.__cause__, FileNotFoundError)  # a worker fault, not bad input
        raise HTTPException(500 if ffmpeg_missing else 422, str(exc)) from exc
    except OSError as exc:
        shutil.rmtree(folder, ignore_errors=True)
        log.exception("Could not save the upload of job %s", job_id)
        raise HTTPException(500, "The worker could not save the video. Check its free disk space.") from exc

    log.info("Job %s queued: %s, %.1f MB", job_id, extension, upload.stat().st_size / 1e6)
    jobs.submit(job_id, upload.name, gender, DUB_VALUES[dub], DUB_VALUES[review], original_volume,
                language or None, DUB_VALUES[separate])  # it may delete the upload from here on
    return {"job_id": job_id}


@app.get("/jobs/{job_id}")
def job_status(job_id: str, request: Request) -> dict:
    """Return the job's status, progress (0 to 100), current step message, error and language.

    `error` is null unless something failed. `language` is the Whisper code of the detected spoken
    language (for example "en"), or null until the Khmer is ready (status review or done). `done`
    and `total` say how far the current step is, or are null: seconds of the video while
    transcribing, lines while translating or making the voice.
    """
    job = _find(request, job_id)
    return {key: job.get(key) for key in PUBLIC_FIELDS}  # .get: status.json files from before "language"


@app.delete("/jobs/{job_id}")
def delete_job(job_id: str, request: Request) -> dict[str, str]:
    """Delete a stopped job with everything in its folder. Answers 409 for a queued or running job."""
    _find(request, job_id)
    request.app.state.jobs.delete(job_id)
    return {"status": "deleted"}


@app.get("/jobs/{job_id}/segments")
def job_segments(job_id: str, request: Request) -> dict[str, list[dict]]:
    """Return the lines of a job in review or done: {"segments": [{id, start, end, text, km}]}. Else 409."""
    if _find(request, job_id)["status"] not in ("review", "done"):
        raise HTTPException(409, NOT_IN_REVIEW)
    folder = request.app.state.jobs.work_dir / job_id
    if not (folder / "segments.json").is_file():
        raise HTTPException(409, NO_LINES)
    fields = ("id", "start", "end", "text", "km")
    return {"segments": [{key: seg[key] for key in fields} for seg in pipeline.load_segments(folder)]}


@app.put("/jobs/{job_id}/segments")
def save_segments(job_id: str, edits: Edits, request: Request) -> dict[str, str]:
    """Save the reviewed Khmer of a job in review and resume it. An empty line is dropped. Else 409."""
    if _find(request, job_id)["status"] != "review":
        raise HTTPException(409, NOT_IN_REVIEW)
    request.app.state.jobs.resume(job_id, edits)
    return {"status": "queued"}


@app.post("/jobs/{job_id}/reopen")
def reopen_job(job_id: str, request: Request, file: Annotated[UploadFile | None, File()] = None) -> dict[str, str]:
    """Take the original video of a job again and put it in review, to make its results again.

    Its Khmer lines and its results stay as they are until PUT /jobs/{id}/segments is done. Works
    on a job in review too, so a refused save can simply be sent again. Answers 409 for a job that
    is running, failed or has no saved lines, and 422 for a missing or unreadable video.
    """
    job, jobs = _find(request, job_id), request.app.state.jobs
    folder = jobs.work_dir / job_id
    if job["status"] not in ("done", "review"):
        raise HTTPException(409, NOT_EDITABLE)
    if not (folder / "segments.json").is_file():
        raise HTTPException(409, NO_LINES)
    if file is None or not file.filename:
        raise HTTPException(422, "No video was sent. Attach it as the multipart field 'file'.")
    extension = Path(file.filename).suffix.lower()
    if extension not in FORMATS:
        raise HTTPException(422, f"Unsupported file type '{extension or 'none'}'. Upload an MP4, MOV, MKV or WebM video.")

    upload = folder / f"input{extension}"
    try:
        _save(file, upload)
        # A job that never stopped for review has no saved length, and some files store none.
        duration = check_video(upload) or extract_audio(upload, folder / "audio.wav")
    except StageError as exc:
        upload.unlink(missing_ok=True)
        raise HTTPException(422, str(exc)) from exc

    jobs.update(job_id, status="review", progress=REVIEW_PROGRESS, message=REVIEW_MESSAGE, error=None,
                upload=upload.name, duration=duration)
    log.info("Job %s reopened for editing", job_id)
    return {"status": "review"}


@app.post("/jobs/{job_id}/cancel")
def cancel_job(job_id: str, request: Request) -> dict[str, str]:
    """Stop a queued or running job and return its status now.

    "cancelled" or "review" means it has stopped; "queued" or "processing" means it stops at its next
    step, so keep reading GET /jobs/{id}. Answers 409 for a job that is not queued or running.
    """
    _find(request, job_id)
    return {"status": request.app.state.jobs.cancel(job_id)}


@app.get("/jobs/{job_id}/download/srt")
def download_srt(job_id: str, request: Request) -> FileResponse:
    """Send the Khmer subtitles of a finished job."""
    return _result(request, job_id, "srt", "application/x-subrip")


@app.get("/jobs/{job_id}/download/video")
def download_video(job_id: str, request: Request) -> FileResponse:
    """Send the Khmer dubbed video of a finished job that was sent with dub=true."""
    return _result(request, job_id, "video", "video/mp4")


@app.post("/cleanup")
def cleanup(request: Request, days: Annotated[float, Form(gt=0)] = 7) -> dict[str, int]:
    """Delete finished jobs created more than `days` days ago (default 7) and return how many."""
    deleted = request.app.state.jobs.cleanup(days)
    log.info("Cleanup older than %g days deleted %d job folders", days, len(deleted))
    return {"deleted": len(deleted)}


def _find(request: Request, job_id: str) -> dict:
    """Return the job's state, or answer 404 for an unknown id."""
    job = request.app.state.jobs.get(job_id)
    if job is None:
        raise HTTPException(404, "Unknown job id.")
    return job


def _result(request: Request, job_id: str, kind: str, media_type: str) -> FileResponse:
    """Send a result file ("srt" or "video") of a finished job, or answer 404 when there is none."""
    job = _find(request, job_id)
    if job["status"] != "done":
        raise HTTPException(404, f"The results are not ready: the job is {job['status']}.")
    if not job.get(kind):
        raise HTTPException(404, "This job has no dubbed video because it was sent with dub=false.")
    path = request.app.state.jobs.work_dir / job_id / job[kind]
    if not path.is_file():
        raise HTTPException(404, "The result file is no longer on the worker.")
    return FileResponse(path, media_type=media_type, filename=path.name)


def _created(folder: Path, job: dict | None) -> datetime:
    """When a job was created: the "created" time in its state, or else its folder's last change."""
    try:
        return datetime.fromisoformat(job["created"])
    except (TypeError, KeyError, ValueError):
        return datetime.fromtimestamp(folder.stat().st_mtime, UTC)


def _token_ok(given: str) -> bool:
    """True if `given` equals WORKER_TOKEN. An empty WORKER_TOKEN refuses everything."""
    expected = config.WORKER_TOKEN
    return bool(expected) and hmac.compare_digest(given.encode(), expected.encode())


def _save(file: UploadFile, path: Path) -> None:
    """Copy the upload to `path` in 1 MB chunks. Raises StageError for an empty file."""
    with path.open("wb") as out:
        shutil.copyfileobj(file.file, out, 1024 * 1024)
    if path.stat().st_size == 0:
        raise StageError("The uploaded video is empty.")


def _tidy(folder: Path, upload: str) -> None:
    """Delete the upload and the working files of a finished job; keep the results and status.json."""
    for name in (upload, "audio.wav", "dub.wav"):
        (folder / name).unlink(missing_ok=True)
    shutil.rmtree(folder / "clips", ignore_errors=True)


def _write_json(path: Path, data: dict) -> None:
    """Write JSON through a temporary file, so a crash never leaves half a status.json."""
    tmp = path.with_name(path.name + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=1), encoding="utf-8")
    os.replace(tmp, path)


def _now() -> str:
    """The current UTC time as an ISO 8601 string."""
    return datetime.now(UTC).isoformat(timespec="seconds")
