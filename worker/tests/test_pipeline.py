"""pipeline.run: the stages in order, the progress milestones and the subtitle-only path."""

import json
import re
from pathlib import Path

import pytest

import pipeline
from stages import StageError
from tests.conftest import SPEECH_DELAY_MS, probe


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


def test_dubbing(fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    def synth_all(segments: list[dict], folder: Path, gender: str) -> list[dict]:
        fake_stages.append(f"voice:{gender}")
        return segments

    def build_track(segments: list[dict], total_ms: int, folder: Path) -> Path:
        fake_stages.append(f"track:{total_ms}")
        return folder / "dub.wav"

    def merge(video: Path, dub_wav: Path, out: Path, original_volume: float | None = None) -> Path:
        fake_stages.append(f"merge:{Path(dub_wav).name}:{original_volume}")
        return out

    monkeypatch.setattr(pipeline, "synth_all", synth_all)
    monkeypatch.setattr(pipeline, "build_track", build_track)
    monkeypatch.setattr(pipeline, "merge", merge)
    milestones: list[int] = []
    result = pipeline.run(tmp_path / "job", "in.mp4", "male", True, lambda p, m: milestones.append(p), 0.4)

    assert fake_stages == ["audio", "transcribe", "translate:en", "voice:male", "track:60000", "merge:dub.wav:0.4"]
    assert milestones == [5, 15, 45, 60, 85, 100]
    assert result["video"] == tmp_path / "job" / "dubbed.mp4"
    assert result["srt"].exists()


def test_edits_saved_between_prepare_and_finish_reach_the_subtitles_and_the_voice(
    fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    voiced: list[str] = []

    def synth_all(segments: list[dict], folder: Path, gender: str) -> list[dict]:
        voiced.extend(seg["km"] for seg in segments)
        return segments

    monkeypatch.setattr(pipeline, "synth_all", synth_all)
    monkeypatch.setattr(pipeline, "build_track", lambda segments, total_ms, folder: folder / "dub.wav")
    monkeypatch.setattr(pipeline, "merge", lambda video, dub_wav, out, original_volume: out)
    job = tmp_path / "job"
    milestones: list[int] = []

    prepared = pipeline.prepare(job, "in.mp4", lambda p, m: milestones.append(p))

    assert prepared == {"language": "en", "duration": 60.0}
    assert milestones == [5, 15, 45]
    assert not (job / "khmer.srt").exists()
    segments = pipeline.load_segments(job)
    segments[0]["km"] = "ជំរាបសួរ"
    pipeline.save_segments(job, segments)

    result = pipeline.finish(job, "in.mp4", "female", True, prepared["duration"], lambda p, m: milestones.append(p))

    assert milestones == [5, 15, 45, 60, 85, 100]
    assert voiced == ["ជំរាបសួរ"]
    assert "ជំរាបសួរ" in result["srt"].read_text(encoding="utf-8")


@pytest.mark.models
def test_real_video_to_khmer_srt(speech_video: Path, tmp_path: Path) -> None:
    result = pipeline.run(tmp_path / "job", speech_video, "female", False, lambda p, m: None)

    srt = result["srt"].read_text(encoding="utf-8")
    first_time = re.search(r"^(\d\d):(\d\d):(\d\d),(\d{3}) --> ", srt, re.MULTILINE)
    h, m, s, ms = map(int, first_time.groups())
    assert h * 3600 + m * 60 + s + ms / 1000 == pytest.approx(SPEECH_DELAY_MS / 1000, abs=0.3)
    assert re.search("[ក-៿]", srt), "no Khmer text in the SRT"
    assert result["language"] == "en"


@pytest.mark.models
def test_real_video_dubbed(speech_video: Path, tmp_path: Path) -> None:
    try:
        result = pipeline.run(tmp_path / "job", speech_video, "female", True, lambda p, m: None)
    except StageError as exc:
        pytest.skip(f"edge-tts unreachable: {exc}")

    assert probe(result["video"], "-show_entries", "stream=codec_type,codec_name") == ["mpeg4,video", "aac,audio"]
    video_length, dubbed_length = (float(probe(p, "-show_entries", "format=duration")[0]) for p in (speech_video, result["video"]))
    assert dubbed_length == pytest.approx(video_length, abs=0.2)
    segments = json.loads((tmp_path / "job" / "segments.json").read_text(encoding="utf-8"))
    assert all(1.0 <= seg["speed"] <= 1.6 for seg in segments if "speed" in seg)
    for seg, after in zip(segments, segments[1:]):
        assert seg.get("dub_end", 0) <= after["start"]
