"""pipeline.run: the stages in order, the progress milestones and the subtitle-only path."""

import json
import re
from collections.abc import Callable
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

    def transcribe(wav_path: Path, on_step: Callable | None = None, language: str | None = None) -> tuple[list[dict], str]:
        calls.append("transcribe")
        return [{"id": 0, "start": 1.0, "end": 2.5, "text": "Hello"}], "en"

    def translate(segments: list[dict], src_lang: str, on_step: Callable | None = None, **terms: object) -> list[dict]:
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
    def no_speech(wav_path: Path, on_step: Callable | None = None, language: str | None = None) -> tuple[list[dict], str]:
        raise StageError("No speech was found in the video.")

    monkeypatch.setattr(pipeline, "transcribe", no_speech)
    milestones: list[int] = []
    with pytest.raises(StageError, match="No speech"):
        pipeline.run(tmp_path / "job", "in.mp4", "female", False, lambda p, m: milestones.append(p))
    assert milestones == [5, 15]
    assert not (tmp_path / "job" / "khmer.srt").exists()


def test_dubbing(fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    def synth_all(segments: list[dict], folder: Path, gender: str, on_step: Callable | None = None, voices: dict | None = None) -> list[dict]:
        fake_stages.append(f"voice:{gender}")
        return segments

    def build_track(segments: list[dict], total_ms: int, folder: Path) -> Path:
        fake_stages.append(f"track:{total_ms}")
        return folder / "dub.wav"

    def merge(video: Path, dub_wav: Path, out: Path, original_volume: float | None = None, srt: Path | None = None, background: Path | None = None) -> Path:
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


def test_a_chosen_language_goes_to_whisper(fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    heard: list = []

    def transcribe(wav_path: Path, on_step: Callable | None = None, language: str | None = None) -> tuple[list[dict], str]:
        heard.append(language)
        return [{"id": 0, "start": 1.0, "end": 2.5, "text": "Bonjour"}], language or "en"

    monkeypatch.setattr(pipeline, "transcribe", transcribe)
    pipeline.run(tmp_path / "auto", "in.mp4", "male", False, lambda *a: None)
    result = pipeline.run(tmp_path / "fr", "in.mp4", "male", False, lambda *a: None, language="fr")
    assert heard == [None, "fr"]
    assert result["language"] == "fr"


def test_removing_the_speech_comes_before_the_mix_and_is_kept(
        fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    separations: list[str] = []
    mixed: list = []

    def separate(video: Path, out: Path, on_step: Callable) -> Path:
        separations.append(str(video))
        on_step(30, 60)
        Path(out).write_bytes(b"wav")
        return Path(out)

    monkeypatch.setattr(pipeline, "synth_all", lambda segments, folder, gender, on_step=None, voices=None: segments)
    monkeypatch.setattr(pipeline, "build_track", lambda segments, total_ms, folder: folder / "dub.wav")
    monkeypatch.setattr(pipeline, "merge", lambda video, dub_wav, out, volume, srt=None, background=None: mixed.append(background) or out)
    monkeypatch.setattr(pipeline, "separate_speech", separate)
    reports: list[tuple] = []
    job = tmp_path / "job"
    pipeline.run(job, "in.mp4", "male", True, lambda *a: reports.append(a), 0.8, separate=True)

    assert separations == ["in.mp4"]
    assert mixed == [job / "background.wav"]
    steps = [r for r in reports if r[0] >= 85]
    assert steps == [(85, "Removing the original speech"), (88, "Removing the original speech", 30, 60),
                     (92, "Mixing and merging"), (100, "Done")]

    pipeline.finish(job, "in.mp4", "male", True, 60.0, lambda *a: None, 0.8, separate=True)
    assert separations == ["in.mp4"], "a second run reuses background.wav"
    pipeline.finish(job, "in.mp4", "male", True, 60.0, lambda *a: None, 0.0, separate=True)
    assert mixed[-1] is None, "with the original sound off nothing is separated or mixed in"


def test_long_stages_report_how_far_they_are(fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    def synth_all(segments: list[dict], folder: Path, gender: str, on_step: Callable, voices: dict | None = None) -> list[dict]:
        on_step(1, 4)
        on_step(4, 4)
        return segments

    def transcribe(wav_path: Path, on_step: Callable, language: str | None = None) -> tuple[list[dict], str]:
        on_step(30, 60)
        return [{"id": 0, "start": 1.0, "end": 2.5, "text": "Hello"}], "en"

    monkeypatch.setattr(pipeline, "transcribe", transcribe)
    monkeypatch.setattr(pipeline, "synth_all", synth_all)
    monkeypatch.setattr(pipeline, "build_track", lambda segments, total_ms, folder: folder / "dub.wav")
    monkeypatch.setattr(pipeline, "merge", lambda video, dub_wav, out, original_volume, srt=None, background=None: out)
    reports: list[tuple] = []

    pipeline.run(tmp_path / "job", "in.mp4", "female", True, lambda *report: reports.append(report))

    assert (29, "Transcribing speech", 30, 60) in reports
    assert (66, "Making the Khmer voice", 1, 4) in reports
    assert (84, "Making the Khmer voice", 4, 4) in reports
    percents = [report[0] for report in reports]
    assert percents == sorted(percents), "the bar never goes back"


def test_making_a_job_again_forgets_the_voice_of_an_emptied_line(
    fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    job = tmp_path / "job"
    job.mkdir()
    pipeline.save_segments(job, [
        {"id": 0, "start": 0.0, "end": 1.0, "text": "a", "km": "", "clip": "clips/0.wav", "speed": 1.2, "cut_ms": 0, "dub_end": 1.0},
        {"id": 1, "start": 1.0, "end": 2.0, "text": "b", "km": "ខ", "clip": "clips/1.wav"},
    ])
    placed: list[str | None] = []

    def build_track(segments: list[dict], total_ms: int, folder: Path) -> Path:
        placed.extend(seg.get("clip") for seg in segments)
        return folder / "dub.wav"

    monkeypatch.setattr(pipeline, "synth_all", lambda segments, folder, gender, on_step, voices=None: segments)
    monkeypatch.setattr(pipeline, "build_track", build_track)
    monkeypatch.setattr(pipeline, "merge", lambda video, dub_wav, out, original_volume, srt=None, background=None: out)

    pipeline.finish(job, "in.mp4", "female", True, 2.0, lambda p, m: None)

    assert placed == [None, None]
    assert "speed" not in pipeline.load_segments(job)[0]


def test_edits_saved_between_prepare_and_finish_reach_the_subtitles_and_the_voice(
    fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    voiced: list[str] = []

    def synth_all(segments: list[dict], folder: Path, gender: str, on_step: Callable | None = None, voices: dict | None = None) -> list[dict]:
        voiced.extend(seg["km"] for seg in segments)
        return segments

    monkeypatch.setattr(pipeline, "synth_all", synth_all)
    monkeypatch.setattr(pipeline, "build_track", lambda segments, total_ms, folder: folder / "dub.wav")
    monkeypatch.setattr(pipeline, "merge", lambda video, dub_wav, out, original_volume, srt=None, background=None: out)
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
    result = pipeline.run(tmp_path / "job", speech_video, "female", False, lambda *report: None)

    srt = result["srt"].read_text(encoding="utf-8")
    first_time = re.search(r"^(\d\d):(\d\d):(\d\d),(\d{3}) --> ", srt, re.MULTILINE)
    h, m, s, ms = map(int, first_time.groups())
    assert h * 3600 + m * 60 + s + ms / 1000 == pytest.approx(SPEECH_DELAY_MS / 1000, abs=0.3)
    assert re.search("[ក-៿]", srt), "no Khmer text in the SRT"
    assert result["language"] == "en"


@pytest.mark.models
def test_real_video_dubbed(speech_video: Path, tmp_path: Path) -> None:
    try:
        result = pipeline.run(tmp_path / "job", speech_video, "female", True, lambda *report: None)
    except StageError as exc:
        pytest.skip(f"edge-tts unreachable: {exc}")

    assert probe(result["video"], "-show_entries", "stream=codec_type,codec_name") == ["mpeg4,video", "aac,audio", "mov_text,subtitle"]
    video_length, dubbed_length = (float(probe(p, "-show_entries", "format=duration")[0]) for p in (speech_video, result["video"]))
    assert dubbed_length == pytest.approx(video_length, abs=0.2)
    segments = json.loads((tmp_path / "job" / "segments.json").read_text(encoding="utf-8"))
    assert all(1.0 <= seg["speed"] <= 1.6 for seg in segments if "speed" in seg)
    for seg, after in zip(segments, segments[1:]):
        assert seg.get("dub_end", 0) <= after["start"]


def test_speakers_are_found_before_the_translation_and_get_their_voices(
    fake_stages: list[str], tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    given: dict = {}

    def find_speakers(wav_path: Path, segments: list[dict], on_step: Callable | None = None) -> list[dict]:
        fake_stages.append("speakers")
        segments[0]["speaker"] = 0
        return [{"id": 0, "gender": "male", "lines": 1}, {"id": 1, "gender": "male", "lines": 0}]

    def synth_all(segments: list[dict], folder: Path, gender: str, on_step: Callable | None = None, voices: dict | None = None) -> list[dict]:
        given.update(voices=voices, speaker=segments[0]["speaker"])
        return segments

    monkeypatch.setattr(pipeline, "find_speakers", find_speakers)
    monkeypatch.setattr(pipeline, "synth_all", synth_all)
    monkeypatch.setattr(pipeline, "build_track", lambda segments, total_ms, folder: folder / "dub.wav")
    monkeypatch.setattr(pipeline, "merge", lambda video, dub_wav, out, original_volume, srt=None, background=None: out)
    milestones: list[int] = []

    pipeline.run(tmp_path / "job", "in.mp4", "female", True, lambda p, m, *how_far: milestones.append(p), speakers=True)

    assert fake_stages == ["audio", "transcribe", "speakers", "translate:en"]
    assert milestones == [5, 15, 38, 45, 60, 85, 100]
    assert pipeline.load_speakers(tmp_path / "job")[0]["gender"] == "male"
    assert given == {"voices": {0: ("male", 0), 1: ("male", -2)}, "speaker": 0}, "the second man sounds lower"


def test_a_job_without_speakers_has_none(fake_stages: list[str], tmp_path: Path) -> None:
    pipeline.prepare(tmp_path / "job", "in.mp4", lambda p, m, *how_far: None)
    assert "speakers" not in fake_stages
    assert pipeline.load_speakers(tmp_path / "job") == []


def test_voice_map_spreads_the_pitch_of_speakers_with_the_same_voice() -> None:
    speakers = [{"id": n, "gender": gender} for n, gender in enumerate(["female", "male", "female", "female", "male"])]
    assert pipeline.voice_map(speakers) == {0: ("female", 0), 1: ("male", 0), 2: ("female", -2), 3: ("female", 2), 4: ("male", -2)}


def test_the_glossary_and_memory_go_to_the_translation(tmp_path: Path, monkeypatch: pytest.MonkeyPatch, fake_stages: list[str]) -> None:
    seen: dict = {}

    def translate(segments: list[dict], src_lang: str, on_step: Callable | None = None, **terms: object) -> list[dict]:
        seen.update(terms)
        segments[0]["km"] = "សួស្តី"
        return segments

    monkeypatch.setattr(pipeline, "translate", translate)
    pipeline.prepare(tmp_path / "job", "in.mp4", lambda p, m, *how_far: None,
                     glossary=[{"term": "Sokha", "km": "សុខា"}], memory=[{"language": "en", "text": "Hi", "km": "ជំរាបសួរ"}])
    assert seen == {"glossary": [{"term": "Sokha", "km": "សុខា"}], "memory": [{"language": "en", "text": "Hi", "km": "ជំរាបសួរ"}]}


def test_clips_no_line_uses_are_pruned(tmp_path: Path) -> None:
    for name in ("used.wav", "used.x1.20.wav", "old.wav"):
        (tmp_path / name).write_bytes(b"")
    pipeline.prune_clips(tmp_path, [{"clip": str(tmp_path / "used.wav")}, {"km": ""}])
    assert [p.name for p in tmp_path.iterdir()] == ["used.wav"]


def test_a_line_is_voiced_like_its_speaker_with_the_unsaved_choices(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    made: list[tuple] = []

    def cached_clip(text: str, folder: Path, gender: str, pitch: int = 0) -> tuple[Path, bool]:
        made.append((text, gender, pitch))
        return folder / "x.wav", True

    monkeypatch.setattr(pipeline, "cached_clip", cached_clip)
    pipeline.save_speakers(tmp_path, [{"id": 0, "gender": "female", "lines": 1}, {"id": 1, "gender": "male", "lines": 1}])

    pipeline.line_voice(tmp_path, "ក", "male")
    pipeline.line_voice(tmp_path, "ក", "male", speaker=1)
    pipeline.line_voice(tmp_path, "ក", "male", speaker=1, voices={0: "male"})  # now two men: the second is lower

    assert made == [("ក", "male", 0), ("ក", "male", 0), ("ក", "male", -2)]
