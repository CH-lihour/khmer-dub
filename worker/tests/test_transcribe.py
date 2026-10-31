"""Stage 2: transcribe. Tests marked `models` use the real Whisper model (WHISPER_MODEL)."""

from pathlib import Path
from types import SimpleNamespace

import pytest

import config
from stages import StageError
from stages.audio import extract_audio
from stages.transcribe import _snap_to_speech, _split_at_pauses, transcribe
from tests.conftest import SPEECH_DELAY_MS

TOLERANCE = 0.3  # seconds between a segment edge and the measured speech edge


def seg(start: float, end: float) -> dict:
    """A segment with only the times _snap_to_speech uses."""
    return {"start": start, "end": end}


def whisper_piece(*words: tuple[float, float, str]) -> SimpleNamespace:
    """A stand-in for a faster-whisper Segment made of (start, end, word) tuples."""
    items = [SimpleNamespace(start=s, end=e, word=w) for s, e, w in words]
    return SimpleNamespace(start=items[0].start, end=items[-1].end, text="".join(w for _, _, w in words), words=items)


def test_split_at_long_pause_between_words() -> None:
    piece = whisper_piece((1.7, 2.1, " Good"), (2.1, 5.5, " test"), (9.1, 9.5, " after"), (9.5, 12.0, " pause."))
    assert _split_at_pauses(piece) == [(1.7, 5.5, " Good test"), (9.1, 12.0, " after pause.")]


def test_short_pauses_do_not_split() -> None:
    piece = whisper_piece((6.0, 8.3, " space."), (8.9, 12.7, " We've"))
    assert _split_at_pauses(piece) == [(6.0, 12.7, " space. We've")]


# The _snap_to_speech cases come from the NASA test clip, where Whisper's times were off.
def test_snap_moves_start_out_of_silence() -> None:
    segments = [seg(31.72, 40.25)]  # speech starts at 33.66
    _snap_to_speech(segments, [(30.82, 31.33), (33.66, 40.10)])
    assert segments == [seg(33.66, 40.10)]


def test_snap_moves_start_out_of_previous_lines_speech() -> None:
    segments = [seg(50.91, 56.83), seg(56.83, 59.19)]  # second line starts in the first one's last word
    _snap_to_speech(segments, [(50.91, 57.06), (57.60, 59.36)])
    assert segments == [seg(50.91, 57.06), seg(57.60, 59.36)]


def test_snap_keeps_boundaries_inside_continuous_speech() -> None:
    segments = [seg(21.28, 26.74), seg(26.74, 28.90)]
    _snap_to_speech(segments, [(21.28, 28.90)])
    assert segments == [seg(21.28, 26.74), seg(26.74, 28.90)]


def test_snap_never_runs_an_end_past_the_next_start() -> None:
    segments = [seg(0.5, 8.7), seg(8.8, 9.5)]
    _snap_to_speech(segments, [(0.0, 9.0)])
    assert segments == [seg(0.5, 8.8), seg(8.8, 9.0)]


def test_snap_without_detected_speech_changes_nothing() -> None:
    segments = [seg(1.0, 2.0)]
    _snap_to_speech(segments, [])
    assert segments == [seg(1.0, 2.0)]


@pytest.mark.models
def test_segment_edges_match_speech(two_sentence_video: tuple, tmp_path: Path) -> None:
    video, first, second = two_sentence_video
    extract_audio(video, tmp_path / "audio.wav")
    segments, _ = transcribe(tmp_path / "audio.wav")

    assert segments[0]["start"] == pytest.approx(first[0], abs=TOLERANCE)
    assert segments[-1]["end"] == pytest.approx(second[1], abs=TOLERANCE)
    # One segment ends where the first sentence does and the next starts with the second one.
    edges = [(a["end"], b["start"]) for a, b in zip(segments, segments[1:])]
    assert any(end == pytest.approx(first[1], abs=TOLERANCE) and start == pytest.approx(second[0], abs=TOLERANCE)
               for end, start in edges), f"no boundary at the pause {first[1]:.2f}-{second[0]:.2f}: {segments}"


@pytest.mark.models
def test_transcribes_english_speech(speech_video: Path, tmp_path: Path) -> None:
    wav_path = tmp_path / "speech.wav"
    extract_audio(speech_video, wav_path)
    segments, language = transcribe(wav_path)

    assert language == "en"
    assert [seg["id"] for seg in segments] == list(range(len(segments)))
    assert all(seg["text"] and seg["start"] < seg["end"] for seg in segments)
    # Times are on the video's clock, not shifted by the silence the VAD removed.
    assert segments[0]["start"] >= SPEECH_DELAY_MS / 1000 - 0.5
    text = " ".join(seg["text"] for seg in segments).lower()
    assert "welcome" in text and "testing" in text


@pytest.mark.models
def test_no_speech_found(silence_wav: Path) -> None:
    with pytest.raises(StageError, match="No speech"):
        transcribe(silence_wav)


@pytest.mark.models
def test_model_load_failure_is_clear(silence_wav: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "WHISPER_MODEL", "no-such-model")
    with pytest.raises(StageError, match="Could not load the speech model 'no-such-model'"):
        transcribe(silence_wav)
