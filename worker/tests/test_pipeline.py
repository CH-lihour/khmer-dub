"""pipeline.run: the stages in order, the progress milestones and the subtitle-only path."""

import json
import re
from pathlib import Path

import pytest

import pipeline
from stages import StageError
from tests.conftest import SPEECH_DELAY_MS


@pytest.fixture
def fake_stages(monkeypatch: pytest.MonkeyPatch) -> list[str]:
    """Replace the stages with quick fakes and return the list of stages called, in order."""
    calls: list[str] = []

    def extract_audio(video_path: Path, wav_path: Path) -> float:
        calls.append("audio")
        return 60.0

    def transcribe(wav_path: Path) -> tuple[list[dict], str]:
        calls.append("transcribe")
        return [{"id": 0, "start": 1.0, "end": 2.5, "text": "Hello"}], "en"

    def translate(segments: list[dict], src_lang: str) -> list[dict]:
        calls.append(f"translate:{src_lang}")
        segments[0]["km"] = "សួស្តី"
        return segments

    monkeypatch.setattr(pipeline, "extract_audio", extract_audio)
    monkeypatch.setattr(pipeline, "transcribe", transcribe)
    monkeypatch.setattr(pipeline, "translate", translate)
    return calls


def test_subtitles_only(fake_stages: list[str], tmp_path: Path) -> None:
    milestones: list[int] = []
    result = pipeline.run(tmp_path / "job", "in.mp4", "female", False, lambda p, m: milestones.append(p))

    assert fake_stages == ["audio", "transcribe", "translate:en"]
    assert milestones == [5, 15, 45, 100]
    assert result == {"language": "en", "segments": 1, "srt": tmp_path / "job" / "khmer.srt", "video": None}
    assert result["srt"].read_text(encoding="utf-8") == "1\n00:00:01,000 --> 00:00:02,500\nសួស្តី\n"
    saved = json.loads((tmp_path / "job" / "segments.json").read_text(encoding="utf-8"))
    assert saved[0]["km"] == "សួស្តី"


def test_stage_error_stops_the_run(fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    def no_speech(wav_path: Path) -> tuple[list[dict], str]:
        raise StageError("No speech was found in the video.")

    monkeypatch.setattr(pipeline, "transcribe", no_speech)
    milestones: list[int] = []
    with pytest.raises(StageError, match="No speech"):
        pipeline.run(tmp_path / "job", "in.mp4", "female", False, lambda p, m: milestones.append(p))
    assert milestones == [5, 15]
    assert not (tmp_path / "job" / "khmer.srt").exists()


def test_dubbing_is_not_built_yet(tmp_path: Path) -> None:
    with pytest.raises(NotImplementedError):
        pipeline.run(tmp_path / "job", "in.mp4", "female", True, lambda p, m: None)


@pytest.mark.models
def test_real_video_to_khmer_srt(speech_video: Path, tmp_path: Path) -> None:
    result = pipeline.run(tmp_path / "job", speech_video, "female", False, lambda p, m: None)

    srt = result["srt"].read_text(encoding="utf-8")
    first_time = re.search(r"^(\d\d):(\d\d):(\d\d),(\d{3}) --> ", srt, re.MULTILINE)
    h, m, s, ms = map(int, first_time.groups())
    assert h * 3600 + m * 60 + s + ms / 1000 == pytest.approx(SPEECH_DELAY_MS / 1000, abs=0.3)
    assert re.search("[ក-៿]", srt), "no Khmer text in the SRT"
    assert result["language"] == "en"
