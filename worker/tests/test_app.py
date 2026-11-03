"""app.py: the API contract, the token check, one job at a time, and status.json across restarts.

The pipeline is replaced by quick fakes; the uploads are real sample videos checked with ffprobe.
"""

import json
import re
import threading
import time
from collections.abc import Callable, Iterator
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

    def run(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress) -> dict:
        calls.append({"video": Path(video_path), "exists": Path(video_path).is_file(), "gender": gender, "dub": dub})
        for percent, message in [(5, "Extracting audio"), (15, "Transcribing speech"), (45, "Translating to Khmer")]:
            progress(percent, message)
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


@pytest.mark.parametrize("path", ["/jobs", "/jobs/0123", "/jobs/0123/download/srt", "/jobs/0123/download/video", "/docs"])
@pytest.mark.parametrize("headers", [{}, {"x-token": "wrong"}, {"x-token": ""}])
def test_wrong_or_missing_token_is_401(client: TestClient, path: str, headers: dict) -> None:
    method = client.post if path == "/jobs" else client.get
    response = method(path, headers=headers)
    assert response.status_code == 401
    assert "x-token" in response.json()["detail"]


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

    assert wait(client, job_id) == {"status": "done", "progress": 100, "message": "Done", "error": None, "language": "en"}
    assert runs == [{"video": work_dir / job_id / "input.mp4", "exists": True, "gender": "male", "dub": True}]

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
    def no_speech(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress) -> dict:
        progress(5, "Extracting audio")
        progress(15, "Transcribing speech")
        raise StageError("No speech was found in the video.")

    monkeypatch.setattr(pipeline, "run", no_speech)
    job_id = upload(client, tone_video).json()["job_id"]
    assert wait(client, job_id) == {
        "status": "failed", "progress": 15, "message": "Failed", "error": "No speech was found in the video.",
        "language": None,
    }
    response = client.get(f"/jobs/{job_id}/download/srt", headers=AUTH)
    assert response.status_code == 404
    assert "not ready" in response.json()["detail"]


def test_crash_fails_the_job_without_details(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    def crash(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress) -> dict:
        raise RuntimeError("secret traceback detail")

    monkeypatch.setattr(pipeline, "run", crash)
    job_id = upload(client, tone_video).json()["job_id"]
    status = wait(client, job_id)
    assert status["status"] == "failed"
    assert status["error"] == worker.UNEXPECTED


def test_jobs_run_one_at_a_time(client: TestClient, tone_video: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    release, started = threading.Event(), []

    def slow(job_dir: Path, video_path: Path, gender: str, dub: bool, progress: Progress) -> dict:
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
            "status": "processing", "progress": 45, "message": "Translating to Khmer", "error": None, "language": None
        }
        assert client.get(f"/jobs/{second}", headers=AUTH).json() == {
            "status": "queued", "progress": 0, "message": "Waiting in the queue", "error": None, "language": None
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
            "status": "failed", "progress": 45, "message": "Failed", "error": worker.RESTARTED, "language": None
        }
        assert client.get("/jobs/notes", headers=AUTH).status_code == 404
