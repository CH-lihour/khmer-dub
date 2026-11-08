"""app.py: the API contract, the token check, one job at a time, and status.json across restarts.

The pipeline is replaced by quick fakes; the uploads are real sample videos checked with ffprobe.
"""

import json
import os
import re
import threading
import time
from collections.abc import Callable, Iterator
from datetime import UTC, datetime, timedelta
from pathlib import Path

import httpx
import pytest
from fastapi.testclient import TestClient

import app as worker
import config
import pipeline
from stages import StageError

TOKEN = "test-token"
AUTH = {"x-token": TOKEN}
SRT = "1\n00:00:01,000 --> 00:00:02,500\nសួស្តី\n"
Progress = Callable[[int, str], None]


@pytest.fixture
def work_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """An empty WORK_DIR and a known token."""
    monkeypatch.setattr(config, "WORKER_TOKEN", TOKEN)
    monkeypatch.setattr(config, "WORK_DIR", tmp_path / "jobs")
    return tmp_path / "jobs"


@pytest.fixture
def runs(monkeypatch: pytest.MonkeyPatch) -> list[dict]:
    """Replace pipeline.run with a fake that reports every milestone and writes the result files."""
    calls: list[dict] = []

    def run(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress, original_volume: float | None = None,
            prepared: dict | None = None, on_prepared: Callable[[dict], None] | None = None,
            language: str | None = None, separate: bool = False) -> dict:
        calls.append({"video": Path(video_path), "exists": Path(video_path).is_file(), "gender": gender, "dub": dub,
                      "original_volume": original_volume, "prepared": prepared, "language": language, "separate": separate})
        if prepared is None:
            for percent, message in [(5, "Extracting audio"), (15, "Transcribing speech"), (45, "Translating to Khmer")]:
                progress(percent, message)
            if on_prepared:
                on_prepared({"language": "en", "duration": 2.0})
        srt = Path(job_dir) / "khmer.srt"
        srt.write_text(SRT, encoding="utf-8")
        video = None
        if dub:
            progress(60, "Making the Khmer voice")
            progress(85, "Mixing and merging")
            (Path(job_dir) / "clips").mkdir()
            video = Path(job_dir) / "dubbed.mp4"
            video.write_bytes(b"fake mp4")
        progress(100, "Done")
        return {"language": "en", "segments": 1, "srt": srt, "video": video}

    monkeypatch.setattr(pipeline, "run", run)
    return calls


@pytest.fixture
def client(work_dir: Path, runs: list[dict]) -> Iterator[TestClient]:
    """A test client with the worker started (its lifespan loads WORK_DIR)."""
    with TestClient(worker.app) as client:
        yield client


def upload(client: TestClient, video: Path, name: str = "clip.mp4", **fields: str) -> httpx.Response:
    """POST /jobs with a video file and the gender and dub fields."""
    data = {"gender": "female", "dub": "true", **fields}
    with video.open("rb") as f:
        return client.post("/jobs", headers=AUTH, data=data, files={"file": (name, f, "video/mp4")})


def wait(client: TestClient, job_id: str) -> dict:
    """Poll GET /jobs/{id} until the job is done or failed, and return the last status."""
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        status = client.get(f"/jobs/{job_id}", headers=AUTH).json()
        if status["status"] in ("done", "failed"):
            return status
        time.sleep(0.02)
    pytest.fail(f"job {job_id} did not finish: {status}")


def test_health_needs_no_token() -> None:
    response = TestClient(worker.app).get("/health")
    assert response.status_code == 200
    assert response.json() == {"ok": True}


@pytest.mark.parametrize(
    "path",
    ["/jobs", "/jobs/0123", "/jobs/0123/segments", "/jobs/0123/cancel", "/jobs/0123/download/srt", "/jobs/0123/download/video", "/cleanup", "/docs"],
)
@pytest.mark.parametrize("headers", [{}, {"x-token": "wrong"}, {"x-token": ""}])
def test_wrong_or_missing_token_is_401(client: TestClient, path: str, headers: dict) -> None:
    method = client.post if path in ("/jobs", "/jobs/0123/cancel", "/cleanup") else client.get
    response = method(path, headers=headers)
    assert response.status_code == 401
    assert "x-token" in response.json()["detail"]


def test_delete_needs_the_token(client: TestClient) -> None:
    assert client.delete("/jobs/0123").status_code == 401


def test_empty_worker_token_refuses_everything(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "WORKER_TOKEN", "")
    assert client.get("/jobs/0123", headers={"x-token": ""}).status_code == 401


def test_only_contract_routes(client: TestClient) -> None:
    for path in ["/docs", "/redoc", "/openapi.json"]:
        assert client.get(path, headers=AUTH).status_code == 404


def test_dub_job_end_to_end(client: TestClient, runs: list[dict], work_dir: Path, tone_video: Path) -> None:
    response = upload(client, tone_video, name="My holiday video.MP4", gender="male", dub="true")
    assert response.status_code == 200
    job_id = response.json()["job_id"]
    assert response.json() == {"job_id": job_id}
    assert re.fullmatch(r"[0-9a-f]{32}", job_id)

    assert wait(client, job_id) == {"status": "done", "progress": 100, "message": "Done", "error": None, "language": "en", "done": None, "total": None}
    assert runs == [{"video": work_dir / job_id / "input.mp4", "exists": True, "gender": "male", "dub": True,
                     "original_volume": None, "prepared": None, "language": None, "separate": False}]

    srt = client.get(f"/jobs/{job_id}/download/srt", headers=AUTH)
    assert srt.status_code == 200
    assert srt.content.decode("utf-8") == SRT
    assert 'filename="khmer.srt"' in srt.headers["content-disposition"]
    video = client.get(f"/jobs/{job_id}/download/video", headers=AUTH)
    assert video.status_code == 200
    assert video.headers["content-type"] == "video/mp4"
    assert video.content == b"fake mp4"

    folder = work_dir / job_id
    saved = json.loads((folder / "status.json").read_text(encoding="utf-8"))
    assert saved["status"] == "done" and saved["language"] == "en"
    assert sorted(p.name for p in folder.iterdir()) == ["dubbed.mp4", "khmer.srt", "status.json"]


def test_subtitles_only_job(client: TestClient, runs: list[dict], tone_video: Path) -> None:
    job_id = upload(client, tone_video, name="talk.webm", dub="false").json()["job_id"]
    assert wait(client, job_id)["status"] == "done"
    assert runs[0]["dub"] is False and runs[0]["video"].name == "input.webm"

    assert client.get(f"/jobs/{job_id}/download/srt", headers=AUTH).status_code == 200
    video = client.get(f"/jobs/{job_id}/download/video", headers=AUTH)
    assert video.status_code == 404
    assert "dub=false" in video.json()["detail"]


def test_defaults_are_female_and_dub(client: TestClient, runs: list[dict], tone_video: Path) -> None:
    with tone_video.open("rb") as f:
        job_id = client.post("/jobs", headers=AUTH, files={"file": ("a.mov", f)}).json()["job_id"]
    wait(client, job_id)
    assert (runs[0]["gender"], runs[0]["dub"]) == ("female", True)


def test_original_volume_reaches_the_pipeline(client: TestClient, runs: list[dict], tone_video: Path) -> None:
    wait(client, upload(client, tone_video, original_volume="0.35").json()["job_id"])
    assert runs[0]["original_volume"] == 0.35


def test_the_spoken_language_and_the_speech_removal_reach_the_pipeline(
        client: TestClient, runs: list[dict], tone_video: Path) -> None:
    job_id = upload(client, tone_video, language="FR", separate="true").json()["job_id"]
    assert client.get(f"/jobs/{job_id}", headers=AUTH).json()["language"] is None, "language stays the detected one"
    wait(client, job_id)
    assert (runs[0]["language"], runs[0]["separate"]) == ("fr", True)


@pytest.mark.parametrize(("field", "value", "detail"), [
    ("language", "la", "language must be a Whisper language code"),  # Whisper knows Latin, NLLB-200 does not
    ("language", "english", "language must be a Whisper language code"),
    ("separate", "maybe", "separate must be true or false."),
])
def test_bad_language_or_separate_is_refused(client: TestClient, work_dir: Path, tone_video: Path,
                                             field: str, value: str, detail: str) -> None:
    response = upload(client, tone_video, **{field: value})
    assert response.status_code == 422
    assert detail in response.json()["detail"]
    assert list(work_dir.iterdir()) == []


def test_a_review_job_uses_its_language_and_keeps_the_speech_removal(
        client: TestClient, finished: list, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    seen: dict = {}
    prepare, finish = pipeline.prepare, pipeline.finish

    def spy_prepare(job_dir: Path, video_path: Path, progress: Progress, language: str | None = None) -> dict:
        seen["language"] = language
        return prepare(job_dir, video_path, progress, language)

    def spy_finish(*args: object, separate: bool = False, **kwargs: object) -> dict:
        seen["separate"] = separate
        return finish(*args, **kwargs)

    monkeypatch.setattr(pipeline, "prepare", spy_prepare)
    monkeypatch.setattr(pipeline, "finish", spy_finish)
    job_id = in_review(client, tone_video, language="ja", separate="true")
    assert edit(client, job_id, (0, "ក")).status_code == 200
    wait(client, job_id)
    assert seen == {"language": "ja", "separate": True}


def test_the_status_says_how_far_a_step_is(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    reached, release = threading.Event(), threading.Event()

    def slow(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress, original_volume: float | None = None, **_: object) -> dict:
        progress(70, "Making the Khmer voice", 12, 29)
        reached.set()
        release.wait(5)
        srt = Path(job_dir) / "khmer.srt"
        srt.write_text(SRT, encoding="utf-8")
        return {"language": "en", "segments": 1, "srt": srt, "video": None}

    monkeypatch.setattr(pipeline, "run", slow)
    job_id = upload(client, tone_video).json()["job_id"]
    assert reached.wait(5)

    assert client.get(f"/jobs/{job_id}", headers=AUTH).json() == {
        "status": "processing", "progress": 70, "message": "Making the Khmer voice", "error": None, "language": None,
        "done": 12, "total": 29,
    }
    release.set()
    assert wait(client, job_id)["done"] is None, "a finished job has no step going on"


def test_unknown_job_is_404(client: TestClient) -> None:
    for path in ["", "/download/srt", "/download/video"]:
        response = client.get(f"/jobs/{'f' * 32}{path}", headers=AUTH)
        assert response.status_code == 404
        assert response.json() == {"detail": "Unknown job id."}


@pytest.mark.parametrize(
    ("name", "fields", "detail"),
    [
        ("clip.avi", {}, "Unsupported file type '.avi'"),
        ("clip", {}, "Unsupported file type 'none'"),
        ("clip.mp4", {"gender": "robot"}, "gender must be female or male"),
        ("clip.mp4", {"dub": "maybe"}, "dub must be true or false"),
        ("clip.mp4", {"original_volume": "1.5"}, "original_volume"),
        ("clip.mp4", {"original_volume": "-0.1"}, "original_volume"),
        ("clip.mp4", {"original_volume": "loud"}, "original_volume"),
    ],
)
def test_bad_fields_are_422(client: TestClient, work_dir: Path, tone_video: Path, name: str, fields: dict, detail: str) -> None:
    response = upload(client, tone_video, name=name, **fields)
    assert response.status_code == 422
    assert detail in response.json()["detail"]
    assert list(work_dir.iterdir()) == []


def test_missing_file_is_422(client: TestClient, work_dir: Path) -> None:
    response = client.post("/jobs", headers=AUTH, data={"gender": "female", "dub": "true"})
    assert response.status_code == 422
    assert response.json() == {"detail": "No video was sent. Attach it as the multipart field 'file'."}
    assert list(work_dir.iterdir()) == []


def test_bad_videos_are_422(client: TestClient, work_dir: Path, tmp_path: Path, silent_video: Path) -> None:
    empty, junk = tmp_path / "empty.mp4", tmp_path / "junk.mp4"
    empty.write_bytes(b"")
    junk.write_text("this is not a video")
    for video, detail in [(empty, "empty"), (junk, "not a video"), (silent_video, "no sound track")]:
        response = upload(client, video)
        assert response.status_code == 422
        assert detail in response.json()["detail"]
    assert list(work_dir.iterdir()) == [], "rejected uploads must not leave a job folder"


def test_too_long_video_is_422(client: TestClient, work_dir: Path, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "MAX_VIDEO_MINUTES", 1 / 60)  # 1 second; the sample is 2 seconds
    response = upload(client, tone_video)
    assert response.status_code == 422
    assert "the limit is" in response.json()["detail"]
    assert list(work_dir.iterdir()) == []


def test_ffmpeg_missing_is_a_worker_fault(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PATH", "")
    response = upload(client, tone_video)
    assert response.status_code == 500
    assert "FFmpeg is not installed" in response.json()["detail"]


def test_stage_error_fails_the_job(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    def no_speech(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress, original_volume: float | None = None, **_: object) -> dict:
        progress(5, "Extracting audio")
        progress(15, "Transcribing speech")
        raise StageError("No speech was found in the video.")

    monkeypatch.setattr(pipeline, "run", no_speech)
    job_id = upload(client, tone_video).json()["job_id"]
    assert wait(client, job_id) == {
        "status": "failed", "progress": 15, "message": "Failed", "error": "No speech was found in the video.",
        "language": None, "done": None, "total": None,
    }
    response = client.get(f"/jobs/{job_id}/download/srt", headers=AUTH)
    assert response.status_code == 404
    assert "not ready" in response.json()["detail"]


def test_crash_fails_the_job_without_details(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    def crash(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress, original_volume: float | None = None, **_: object) -> dict:
        raise RuntimeError("secret traceback detail")

    monkeypatch.setattr(pipeline, "run", crash)
    job_id = upload(client, tone_video).json()["job_id"]
    status = wait(client, job_id)
    assert status["status"] == "failed"
    assert status["error"] == worker.UNEXPECTED


def test_jobs_run_one_at_a_time(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    release, started = threading.Event(), []

    def slow(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress, original_volume: float | None = None, **_: object) -> dict:
        started.append(Path(job_dir).name)
        progress(45, "Translating to Khmer")
        release.wait(10)
        srt = Path(job_dir) / "khmer.srt"
        srt.write_text(SRT, encoding="utf-8")
        return {"language": "en", "segments": 1, "srt": srt, "video": None}

    monkeypatch.setattr(pipeline, "run", slow)
    try:
        first = upload(client, tone_video, dub="false").json()["job_id"]
        second = upload(client, tone_video, dub="false").json()["job_id"]
        deadline = time.monotonic() + 5
        while not started and time.monotonic() < deadline:
            time.sleep(0.02)
        time.sleep(0.2)  # give a second thread, if there were one, time to start too
        assert started == [first]
        assert client.get(f"/jobs/{first}", headers=AUTH).json() == {
            "status": "processing", "progress": 45, "message": "Translating to Khmer", "error": None, "language": None, "done": None, "total": None
        }
        assert client.get(f"/jobs/{second}", headers=AUTH).json() == {
            "status": "queued", "progress": 0, "message": "Waiting in the queue", "error": None, "language": None, "done": None, "total": None
        }
    finally:
        release.set()
    assert wait(client, first)["status"] == wait(client, second)["status"] == "done"
    assert started == [first, second]


def test_jobs_survive_a_restart(work_dir: Path, runs: list[dict], tone_video: Path) -> None:
    with TestClient(worker.app) as client:
        done = upload(client, tone_video).json()["job_id"]
        assert wait(client, done)["status"] == "done"

    # A job that was running when the worker stopped (saved before "language" existed), and a folder that is not a job.
    interrupted = "a" * 32
    (work_dir / interrupted).mkdir()
    (work_dir / interrupted / "status.json").write_text(json.dumps(
        {"status": "processing", "progress": 45, "message": "Translating to Khmer", "error": None}))
    (work_dir / "notes").mkdir()

    with TestClient(worker.app) as client:
        assert client.get(f"/jobs/{done}", headers=AUTH).json()["language"] == "en"
        assert client.get(f"/jobs/{done}/download/srt", headers=AUTH).content.decode("utf-8") == SRT
        assert client.get(f"/jobs/{interrupted}", headers=AUTH).json() == {
            "status": "failed", "progress": 45, "message": "Failed", "error": worker.RESTARTED, "language": None, "done": None, "total": None
        }
        assert client.get("/jobs/notes", headers=AUTH).status_code == 404


def days_ago(days: float) -> str:
    """An ISO time `days` days in the past, as stored in "created"."""
    return (datetime.now(UTC) - timedelta(days=days)).isoformat(timespec="seconds")


def test_cleanup_deletes_only_old_finished_jobs(
    client: TestClient, work_dir: Path, tone_video: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    jobs = worker.app.state.jobs
    old_done, new_done, old_running = (upload(client, tone_video).json()["job_id"] for _ in range(3))
    for job_id in (old_done, new_done, old_running):
        wait(client, job_id)
    jobs.update(old_done, created=days_ago(8))
    jobs.update(old_running, status="processing", created=days_ago(8))  # as if it were still running

    def no_speech(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress, original_volume: float | None = None, **_: object) -> dict:
        raise StageError("No speech was found in the video.")

    monkeypatch.setattr(pipeline, "run", no_speech)
    old_failed = upload(client, tone_video).json()["job_id"]
    wait(client, old_failed)
    jobs.update(old_failed, created=days_ago(10))

    # Folders the worker has no state for: dated by their last change.
    old_orphan, new_orphan, not_a_job = work_dir / ("b" * 32), work_dir / ("c" * 32), work_dir / "notes"
    for folder in (old_orphan, new_orphan, not_a_job):
        folder.mkdir()
    eight_days = time.time() - 8 * 86400
    for folder in (old_orphan, not_a_job):
        os.utime(folder, (eight_days, eight_days))

    response = client.post("/cleanup", headers=AUTH, data={"days": "7"})

    assert response.status_code == 200
    assert response.json() == {"deleted": 3}
    assert sorted(p.name for p in work_dir.iterdir()) == sorted([new_done, old_running, new_orphan.name, "notes"])
    assert client.get(f"/jobs/{old_done}", headers=AUTH).status_code == 404
    assert client.get(f"/jobs/{old_failed}", headers=AUTH).status_code == 404
    assert client.get(f"/jobs/{new_done}/download/srt", headers=AUTH).status_code == 200
    assert client.get(f"/jobs/{old_running}", headers=AUTH).json()["status"] == "processing"


def test_cleanup_defaults_to_7_days(client: TestClient, tone_video: Path) -> None:
    job_id = upload(client, tone_video).json()["job_id"]
    wait(client, job_id)
    worker.app.state.jobs.update(job_id, created=days_ago(6.9))

    assert client.post("/cleanup", headers=AUTH).json() == {"deleted": 0}
    worker.app.state.jobs.update(job_id, created=days_ago(7.1))
    assert client.post("/cleanup", headers=AUTH).json() == {"deleted": 1}


@pytest.mark.parametrize("days", ["0", "-1", "soon"])
def test_cleanup_needs_a_positive_number_of_days(client: TestClient, days: str) -> None:
    response = client.post("/cleanup", headers=AUTH, data={"days": days})
    assert response.status_code == 422
    assert "days" in response.json()["detail"]


LINES = [
    {"id": 0, "start": 1.0, "end": 2.5, "text": "Hello", "km": "សួស្តី"},
    {"id": 1, "start": 3.0, "end": 4.0, "text": "Goodbye", "km": "លា"},
]


@pytest.fixture
def finished(monkeypatch: pytest.MonkeyPatch) -> list[dict]:
    """Replace pipeline.prepare and finish with fakes; return what each finish call saw."""
    calls: list[dict] = []

    def prepare(job_dir: Path, video_path: Path, progress: Progress, language: str | None = None) -> dict:
        progress(45, "Translating to Khmer")
        pipeline.save_segments(job_dir, [dict(seg) for seg in LINES])
        return {"language": "en", "duration": 60.0}

    def finish(job_dir: Path, video_path: Path, gender: str, dub: bool, duration: float, progress: Progress,
               original_volume: float | None = None, **_: object) -> dict:
        segments = pipeline.load_segments(job_dir)
        calls.append({"km": [seg["km"] for seg in segments], "gender": gender, "dub": dub, "duration": duration,
                      "original_volume": original_volume, "upload_kept": Path(video_path).is_file()})
        progress(100, "Done")
        srt = Path(job_dir) / "khmer.srt"
        srt.write_text("\n".join(seg["km"] for seg in segments), encoding="utf-8")
        return {"segments": len(segments), "srt": srt, "video": None}

    monkeypatch.setattr(pipeline, "prepare", prepare)
    monkeypatch.setattr(pipeline, "finish", finish)
    return calls


def wait_for(client: TestClient, job_id: str, *statuses: str) -> dict:
    """Poll GET /jobs/{id} until the job has one of `statuses`, and return the last status."""
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        status = client.get(f"/jobs/{job_id}", headers=AUTH).json()
        if status["status"] in statuses:
            return status
        time.sleep(0.02)
    pytest.fail(f"job {job_id} did not reach {statuses}: {status}")


def in_review(client: TestClient, video: Path, **fields: str) -> str:
    """Upload a video for review, wait until it stops, and return its job id."""
    job_id = upload(client, video, review="true", **fields).json()["job_id"]
    wait_for(client, job_id, "review")
    return job_id


def edit(client: TestClient, job_id: str, *lines: tuple[int, str]) -> httpx.Response:
    """PUT /jobs/{id}/segments with (id, km) pairs."""
    body = {"segments": [{"id": line_id, "km": km} for line_id, km in lines]}
    return client.put(f"/jobs/{job_id}/segments", headers=AUTH, json=body)


def test_review_job_stops_after_translation_and_shows_its_lines(client: TestClient, finished: list, tone_video: Path) -> None:
    job_id = upload(client, tone_video, review="true").json()["job_id"]

    assert wait_for(client, job_id, "review") == {
        "status": "review", "progress": 50, "message": "Waiting for your review", "error": None, "language": "en", "done": None, "total": None
    }
    assert finished == [], "the voice must wait for the review"
    response = client.get(f"/jobs/{job_id}/segments", headers=AUTH)
    assert response.status_code == 200
    assert response.json() == {"segments": LINES}
    assert client.get(f"/jobs/{job_id}/download/srt", headers=AUTH).status_code == 404


def test_original_volume_is_kept_through_the_review(client: TestClient, finished: list, tone_video: Path) -> None:
    job_id = in_review(client, tone_video, original_volume="0.5")

    assert edit(client, job_id, (1, "ជម្រាបលា")).status_code == 200

    wait(client, job_id)
    assert finished[0]["original_volume"] == 0.5


def test_saved_edits_reach_the_results(client: TestClient, finished: list, work_dir: Path, tone_video: Path) -> None:
    job_id = in_review(client, tone_video, gender="male", dub="false")

    response = edit(client, job_id, (1, "  ជម្រាបលា  "))

    assert response.status_code == 200
    assert wait(client, job_id) == {"status": "done", "progress": 100, "message": "Done", "error": None, "language": "en", "done": None, "total": None}
    assert finished == [{"km": ["សួស្តី", "ជម្រាបលា"], "gender": "male", "dub": False, "duration": 60.0,
                         "original_volume": None, "upload_kept": True}]
    assert client.get(f"/jobs/{job_id}/download/srt", headers=AUTH).content.decode("utf-8") == "សួស្តី\nជម្រាបលា"
    assert not (work_dir / job_id / "input.mp4").exists(), "the upload is tidied away once the job is done"


def test_an_empty_line_is_dropped(client: TestClient, finished: list, tone_video: Path) -> None:
    job_id = in_review(client, tone_video)
    assert edit(client, job_id, (0, "   ")).status_code == 200
    wait(client, job_id)
    assert finished[0]["km"] == ["", "លា"]


def test_a_job_in_review_holds_no_thread(client: TestClient, runs: list, finished: list, tone_video: Path) -> None:
    waiting = in_review(client, tone_video)
    other = upload(client, tone_video).json()["job_id"]
    assert wait(client, other)["status"] == "done"
    assert client.get(f"/jobs/{waiting}", headers=AUTH).json()["status"] == "review"


@pytest.mark.parametrize(
    "lines, detail",
    [
        ([(7, "x")], "not part of this job: [7]"),
        ([(0, ""), (1, " ")], "at least one line"),
        ([(0, "ក" * 2001)], "km"),
    ],
)
def test_bad_edits_change_nothing(client: TestClient, finished: list, tone_video: Path, lines: list, detail: str) -> None:
    job_id = in_review(client, tone_video)

    response = edit(client, job_id, *lines)

    assert response.status_code == 422
    assert detail in response.json()["detail"]
    assert client.get(f"/jobs/{job_id}", headers=AUTH).json()["status"] == "review"
    assert client.get(f"/jobs/{job_id}/segments", headers=AUTH).json() == {"segments": LINES}


def test_lines_with_no_khmer_cannot_be_saved(client: TestClient, finished: list, tone_video: Path) -> None:
    job_id = in_review(client, tone_video)

    response = edit(client, job_id, (1, "සිංහල අකුරු"))

    assert response.status_code == 422
    assert response.json() == {"detail": "These lines have no Khmer text: [2]. Write them in Khmer, or empty them."}
    assert client.get(f"/jobs/{job_id}", headers=AUTH).json()["status"] == "review"
    assert finished == []


def test_khmer_with_a_latin_name_or_only_digits_can_be_saved(client: TestClient, finished: list, tone_video: Path) -> None:
    job_id = in_review(client, tone_video)
    assert edit(client, job_id, (0, "សួស្តី NASA"), (1, "2026")).status_code == 200
    wait(client, job_id)
    assert finished[0]["km"] == ["សួស្តី NASA", "2026"]


def test_review_routes_need_a_job_in_review(client: TestClient, finished: list, tone_video: Path) -> None:
    job_id = in_review(client, tone_video)
    assert edit(client, job_id, (0, "ក")).status_code == 200
    wait(client, job_id)

    assert edit(client, job_id, (0, "ខ")).status_code == 409, "saving twice must not run the job twice"
    assert client.get(f"/jobs/{job_id}/segments", headers=AUTH).status_code == 200, "a done job can show its lines"
    assert len(finished) == 1
    assert client.get(f"/jobs/{'f' * 32}/segments", headers=AUTH).status_code == 404
    assert edit(client, "f" * 32, (0, "ក")).status_code == 404


def reopen(client: TestClient, job_id: str, video: Path, name: str = "clip.mp4") -> httpx.Response:
    """POST /jobs/{id}/reopen with the original video."""
    with video.open("rb") as f:
        return client.post(f"/jobs/{job_id}/reopen", headers=AUTH, files={"file": (name, f, "video/mp4")})


def finished_job(client: TestClient, video: Path, **fields: str) -> str:
    """A job that went through review to the end, and its id."""
    job_id = in_review(client, video, **fields)
    assert edit(client, job_id, (0, "ក")).status_code == 200
    wait(client, job_id)
    return job_id


def test_a_finished_job_can_be_edited_and_made_again(client: TestClient, finished: list, work_dir: Path, tone_video: Path) -> None:
    job_id = finished_job(client, tone_video, gender="female", original_volume="0.5")

    response = reopen(client, job_id, tone_video)

    assert response.status_code == 200
    assert response.json() == {"status": "review"}
    assert client.get(f"/jobs/{job_id}", headers=AUTH).json() == {
        "status": "review", "progress": 50, "message": "Waiting for your review", "error": None, "language": "en", "done": None, "total": None
    }
    assert client.get(f"/jobs/{job_id}/segments", headers=AUTH).json()["segments"][0]["km"] == "ក"

    body = {"segments": [{"id": 1, "km": "ថ្មី"}], "gender": "male", "original_volume": 0}
    assert client.put(f"/jobs/{job_id}/segments", headers=AUTH, json=body).status_code == 200
    assert wait(client, job_id)["status"] == "done"

    again = finished[-1]
    assert len(finished) == 2
    assert (again["km"], again["gender"], again["original_volume"]) == (["ក", "ថ្មី"], "male", 0)
    assert again["upload_kept"] is True
    assert again["duration"] == pytest.approx(2, abs=0.5), "measured from the video sent again"
    assert not (work_dir / job_id / "input.mp4").exists(), "the upload is tidied away again"


def test_saving_leaves_out_choices_that_were_not_sent(client: TestClient, finished: list, tone_video: Path) -> None:
    job_id = finished_job(client, tone_video, gender="male", original_volume="0.5")
    assert reopen(client, job_id, tone_video).status_code == 200
    assert edit(client, job_id, (0, "ខ")).status_code == 200
    wait(client, job_id)
    assert (finished[-1]["gender"], finished[-1]["original_volume"]) == ("male", 0.5)


def test_a_refused_save_can_be_sent_again(client: TestClient, finished: list, tone_video: Path) -> None:
    job_id = finished_job(client, tone_video)
    assert reopen(client, job_id, tone_video).status_code == 200
    assert edit(client, job_id, (0, ""), (1, "")).status_code == 422
    assert reopen(client, job_id, tone_video).status_code == 200, "sending the video twice does no harm"
    assert edit(client, job_id, (0, "ខ")).status_code == 200


@pytest.mark.parametrize(
    "fields, problem",
    [({"gender": "robot"}, "gender must be female or male"), ({"original_volume": 1.5}, "original_volume")],
)
def test_bad_choices_are_not_saved(client: TestClient, finished: list, tone_video: Path, fields: dict, problem: str) -> None:
    job_id = in_review(client, tone_video)
    response = client.put(f"/jobs/{job_id}/segments", headers=AUTH, json={"segments": [], **fields})
    assert response.status_code == 422
    assert problem in response.json()["detail"]
    assert client.get(f"/jobs/{job_id}", headers=AUTH).json()["status"] == "review"


def test_reopen_refuses_what_it_cannot_use(client: TestClient, finished: list, tone_video: Path, tmp_path: Path) -> None:
    job_id = finished_job(client, tone_video)
    junk = tmp_path / "junk.mp4"
    junk.write_bytes(b"not a video")

    assert reopen(client, job_id, tone_video, name="clip.avi").status_code == 422
    assert reopen(client, job_id, junk).status_code == 422
    assert client.post(f"/jobs/{job_id}/reopen", headers=AUTH).status_code == 422
    assert client.get(f"/jobs/{job_id}", headers=AUTH).json()["status"] == "done"
    assert reopen(client, "f" * 32, tone_video).status_code == 404

    client.app.state.jobs.update(job_id, status="failed")
    assert reopen(client, job_id, tone_video).status_code == 409


def test_a_job_without_saved_lines_cannot_be_reopened(client: TestClient, runs: list, tone_video: Path) -> None:
    job_id = upload(client, tone_video).json()["job_id"]  # the fake pipeline saves no lines
    wait(client, job_id)
    response = reopen(client, job_id, tone_video)
    assert response.status_code == 409
    assert "no saved lines" in response.json()["detail"]
    assert client.get(f"/jobs/{job_id}/segments", headers=AUTH).status_code == 409


def test_saving_while_the_job_is_still_running_is_409(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    release = threading.Event()

    def slow(job_dir: Path, video_path: Path, progress: Progress) -> dict:
        release.wait(10)
        raise StageError("stopped")

    monkeypatch.setattr(pipeline, "prepare", slow)
    job_id = upload(client, tone_video, review="true").json()["job_id"]
    try:
        assert edit(client, job_id, (0, "ក")).status_code == 409
    finally:
        release.set()
    wait(client, job_id)


def cancel(client: TestClient, job_id: str) -> httpx.Response:
    """POST /jobs/{id}/cancel."""
    return client.post(f"/jobs/{job_id}/cancel", headers=AUTH)


@pytest.fixture
def held(monkeypatch: pytest.MonkeyPatch) -> Iterator[dict]:
    """A pipeline.run that reports one step, waits for "release", then reports the next ones."""
    state = {"started": threading.Event(), "release": threading.Event(), "reached": []}

    def run(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress, original_volume: float | None = None, **_: object) -> dict:
        progress(5, "Extracting audio")
        state["started"].set()
        state["release"].wait(10)
        progress(15, "Transcribing speech")
        state["reached"].append(video_path)
        srt = Path(job_dir) / "khmer.srt"
        srt.write_text(SRT, encoding="utf-8")
        return {"language": "en", "segments": 1, "srt": srt, "video": None}

    monkeypatch.setattr(pipeline, "run", run)
    yield state
    state["release"].set()


def test_a_running_job_stops_at_its_next_step(client: TestClient, held: dict, work_dir: Path, tone_video: Path) -> None:
    job_id = upload(client, tone_video).json()["job_id"]
    assert held["started"].wait(10)

    response = cancel(client, job_id)
    assert response.status_code == 200
    assert response.json() == {"status": "processing"}  # it has not reached a step yet
    held["release"].set()

    status = wait_for(client, job_id, "cancelled")
    assert status["message"] == "Cancelled"
    assert (status["error"], status["done"], status["total"]) == (None, None, None)
    assert held["reached"] == []
    assert not (work_dir / job_id / "input.mp4").exists()
    assert client.get(f"/jobs/{job_id}/download/srt", headers=AUTH).status_code == 404
    assert client.get(f"/jobs/{job_id}/segments", headers=AUTH).status_code == 409


def test_a_job_in_line_stops_at_once_and_never_runs(client: TestClient, held: dict, tone_video: Path) -> None:
    first = upload(client, tone_video).json()["job_id"]
    assert held["started"].wait(10)
    second = upload(client, tone_video).json()["job_id"]

    assert cancel(client, second).json() == {"status": "cancelled"}
    assert cancel(client, first).json() == {"status": "processing"}
    held["release"].set()

    wait_for(client, first, "cancelled")
    assert held["reached"] == []
    assert client.get(f"/jobs/{second}", headers=AUTH).json()["status"] == "cancelled"


def test_the_next_job_runs_after_a_cancelled_one(client: TestClient, held: dict, tone_video: Path) -> None:
    first = upload(client, tone_video).json()["job_id"]
    assert held["started"].wait(10)
    second = upload(client, tone_video).json()["job_id"]
    cancel(client, first)
    held["release"].set()

    wait_for(client, first, "cancelled")
    assert wait(client, second)["status"] == "done"


def test_only_a_queued_or_running_job_can_be_cancelled(client: TestClient, finished: list, tone_video: Path) -> None:
    assert cancel(client, "0" * 32).status_code == 404
    job_id = in_review(client, tone_video)
    assert cancel(client, job_id).status_code == 409
    assert edit(client, job_id, (0, "ពិនិត្យរួច")).status_code == 200
    wait(client, job_id)
    response = cancel(client, job_id)
    assert response.status_code == 409
    assert "nothing to cancel" in response.json()["detail"]


def test_cancelling_after_the_review_keeps_the_edits(
    client: TestClient, finished: list, tone_video: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    job_id = in_review(client, tone_video)
    working_finish, started, release = pipeline.finish, threading.Event(), threading.Event()

    def held_finish(job_dir: Path, video_path: Path, gender: str, dub: bool, duration: float, progress: Progress,
                    original_volume: float | None = None, **_: object) -> dict:
        progress(60, "Making the Khmer voice")
        started.set()
        release.wait(10)
        progress(85, "Mixing and merging")
        pytest.fail("the job went on after it was cancelled")

    monkeypatch.setattr(pipeline, "finish", held_finish)
    assert edit(client, job_id, (0, "ពិនិត្យរួច")).status_code == 200
    assert started.wait(10)
    assert cancel(client, job_id).json() == {"status": "processing"}
    release.set()

    status = wait_for(client, job_id, "review")
    assert status["progress"] == worker.REVIEW_PROGRESS
    assert (status["message"], status["error"]) == (worker.REVIEW_MESSAGE, None)
    lines = client.get(f"/jobs/{job_id}/segments", headers=AUTH).json()["segments"]
    assert lines[0]["km"] == "ពិនិត្យរួច"

    monkeypatch.setattr(pipeline, "finish", working_finish)
    assert edit(client, job_id, (0, "ម្ដងទៀត")).status_code == 200
    assert wait(client, job_id)["status"] == "done"


def test_a_reviewed_job_in_line_goes_back_to_review_and_cannot_run_twice(
    client: TestClient, finished: list, held: dict, tone_video: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    job_id = in_review(client, tone_video)
    blocker = upload(client, tone_video).json()["job_id"]
    assert held["started"].wait(10)
    assert edit(client, job_id, (0, "ពិនិត្យរួច")).status_code == 200

    assert cancel(client, job_id).json() == {"status": "review"}
    assert edit(client, job_id, (0, "ម្ដងទៀត")).status_code == 200
    held["release"].set()

    wait(client, blocker)
    assert wait(client, job_id)["status"] == "done"
    assert len(finished) == 1


def test_a_stopped_job_can_be_deleted(client: TestClient, work_dir: Path, tone_video: Path) -> None:
    job_id = upload(client, tone_video).json()["job_id"]
    wait(client, job_id)
    assert (work_dir / job_id / "dubbed.mp4").is_file()

    response = client.delete(f"/jobs/{job_id}", headers=AUTH)

    assert response.status_code == 200
    assert response.json() == {"status": "deleted"}
    assert not (work_dir / job_id).exists()
    assert client.get(f"/jobs/{job_id}", headers=AUTH).status_code == 404
    assert client.delete(f"/jobs/{job_id}", headers=AUTH).status_code == 404


def test_a_job_in_review_can_be_deleted(client: TestClient, finished: list, work_dir: Path, tone_video: Path) -> None:
    job_id = in_review(client, tone_video)

    assert client.delete(f"/jobs/{job_id}", headers=AUTH).status_code == 200
    assert not (work_dir / job_id).exists()


def test_a_deleted_job_stays_gone_after_a_restart(work_dir: Path, runs: list[dict], tone_video: Path) -> None:
    with TestClient(worker.app) as first:
        job_id = upload(first, tone_video).json()["job_id"]
        wait(first, job_id)
        first.delete(f"/jobs/{job_id}", headers=AUTH)
    with TestClient(worker.app) as second:
        assert second.get(f"/jobs/{job_id}", headers=AUTH).status_code == 404


def test_a_running_job_cannot_be_deleted(client: TestClient, held: dict, work_dir: Path, tone_video: Path) -> None:
    job_id = upload(client, tone_video).json()["job_id"]
    assert held["started"].wait(10)

    response = client.delete(f"/jobs/{job_id}", headers=AUTH)

    assert response.status_code == 409
    assert "Cancel it first" in response.json()["detail"]
    assert (work_dir / job_id).is_dir()
    held["release"].set()
    wait(client, job_id)


def test_failed_voice_keeps_the_edits_and_can_be_retried(
    client: TestClient, finished: list, tone_video: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    job_id = in_review(client, tone_video)
    working_finish = pipeline.finish

    def voice_down(job_dir: Path, video_path: Path, gender: str, dub: bool, duration: float, progress: Progress,
               original_volume: float | None = None, **_: object) -> dict:
        raise StageError("The Khmer voice service (edge-tts) could not be reached.")

    monkeypatch.setattr(pipeline, "finish", voice_down)
    assert edit(client, job_id, (0, "ពិនិត្យរួច")).status_code == 200
    deadline = time.monotonic() + 10
    while client.get(f"/jobs/{job_id}", headers=AUTH).json()["error"] is None and time.monotonic() < deadline:
        time.sleep(0.02)

    status = wait_for(client, job_id, "review")
    assert status["error"] == "The Khmer voice service (edge-tts) could not be reached."
    assert status["progress"] == 50
    assert client.get(f"/jobs/{job_id}/segments", headers=AUTH).json()["segments"][0]["km"] == "ពិនិត្យរួច"

    monkeypatch.setattr(pipeline, "finish", working_finish)
    assert edit(client, job_id).status_code == 200
    assert wait(client, job_id) == {"status": "done", "progress": 100, "message": "Done", "error": None, "language": "en", "done": None, "total": None}
    assert finished[0]["km"][0] == "ពិនិត្យរួច"


def test_a_failed_translation_fails_the_job(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    def no_speech(job_dir: Path, video_path: Path, progress: Progress, language: str | None = None) -> dict:
        raise StageError("No speech was found in the video.")

    monkeypatch.setattr(pipeline, "prepare", no_speech)
    job_id = upload(client, tone_video, review="true").json()["job_id"]
    assert wait(client, job_id)["error"] == "No speech was found in the video."


def test_a_review_survives_a_restart(work_dir: Path, runs: list[dict], finished: list, tone_video: Path) -> None:
    with TestClient(worker.app) as client:
        job_id = in_review(client, tone_video)

    with TestClient(worker.app) as client:
        assert client.get(f"/jobs/{job_id}", headers=AUTH).json()["status"] == "review"
        assert edit(client, job_id, (0, "ក")).status_code == 200
        assert wait(client, job_id)["status"] == "done"
    assert finished[0]["duration"] == 60.0


def test_a_restart_during_the_voice_keeps_the_edits(work_dir: Path, runs: list[dict], finished: list, tone_video: Path) -> None:
    with TestClient(worker.app) as client:
        job_id = in_review(client, tone_video)
        pipeline.save_segments(work_dir / job_id, [{**LINES[0], "km": "ពិនិត្យរួច"}, LINES[1]])
        worker.app.state.jobs.update(job_id, status="processing", progress=60, message="Making the Khmer voice")

    with TestClient(worker.app) as client:  # it goes on by itself, with the edits
        assert wait(client, job_id)["status"] == "done"
    assert finished[-1]["km"][0] == "ពិនិត្យរួច"


def test_a_second_restart_of_the_same_voice_puts_it_back_in_review(
        work_dir: Path, runs: list[dict], finished: list, tone_video: Path) -> None:
    with TestClient(worker.app) as client:
        job_id = in_review(client, tone_video)
        pipeline.save_segments(work_dir / job_id, [{**LINES[0], "km": "ពិនិត្យរួច"}, LINES[1]])
        worker.app.state.jobs.update(job_id, status="processing", progress=60, message="Making the Khmer voice", restarts=1)

    with TestClient(worker.app) as client:
        assert client.get(f"/jobs/{job_id}", headers=AUTH).json() == {
            "status": "review", "progress": 50, "message": "Waiting for your review",
            "error": worker.RESTARTED_REVIEW, "language": "en", "done": None, "total": None,
        }
        assert client.get(f"/jobs/{job_id}/segments", headers=AUTH).json()["segments"][0]["km"] == "ពិនិត្យរួច"
        assert edit(client, job_id).status_code == 200
        assert wait(client, job_id)["status"] == "done"
        assert worker.app.state.jobs.get(job_id)["restarts"] == 0, "a later restart may go on again"


def test_a_job_interrupted_before_its_voice_goes_on_after_a_restart(
        work_dir: Path, runs: list[dict], tone_video: Path) -> None:
    with TestClient(worker.app) as client:
        early = upload(client, tone_video).json()["job_id"]
        translated = upload(client, tone_video).json()["job_id"]
        assert wait(client, early)["status"] == wait(client, translated)["status"] == "done"
        jobs = worker.app.state.jobs
        jobs.update(early, status="processing", progress=15, message="Transcribing speech", created=days_ago(0.2), prepared=None)
        jobs.update(translated, status="processing", progress=60, message="Making the Khmer voice", created=days_ago(0.1),
                    prepared={"language": "en", "duration": 2.0})
        for job_id in (early, translated):  # finishing tidied the uploads away
            (work_dir / job_id / "input.mp4").write_bytes(tone_video.read_bytes())
        runs.clear()

    with TestClient(worker.app) as client:
        assert wait(client, early)["status"] == "done"
        assert wait(client, translated)["status"] == "done"
    assert [call["prepared"] for call in runs] == [None, {"language": "en", "duration": 2.0}], "in their old order, from their last step"


def test_review_field_must_be_true_or_false(client: TestClient, work_dir: Path, tone_video: Path) -> None:
    response = upload(client, tone_video, review="maybe")
    assert response.status_code == 422
    assert response.json() == {"detail": "review must be true or false."}
    assert list(work_dir.iterdir()) == []


def test_cleanup_deletes_an_abandoned_review(client: TestClient, finished: list, work_dir: Path, tone_video: Path) -> None:
    job_id = in_review(client, tone_video)
    worker.app.state.jobs.update(job_id, created=days_ago(8))

    assert client.post("/cleanup", headers=AUTH, data={"days": "7"}).json() == {"deleted": 1}
    assert list(work_dir.iterdir()) == []
