"""Stage 2: transcribe. Uses the real Whisper model (WHISPER_MODEL), downloaded on the first run."""

from pathlib import Path

import pytest

import config
from stages import StageError
from stages.audio import extract_audio
from stages.transcribe import transcribe
from tests.conftest import SPEECH_DELAY_MS

pytestmark = pytest.mark.models


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


def test_no_speech_found(silence_wav: Path) -> None:
    with pytest.raises(StageError, match="No speech"):
        transcribe(silence_wav)


def test_model_load_failure_is_clear(silence_wav: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "WHISPER_MODEL", "no-such-model")
    with pytest.raises(StageError, match="Could not load the speech model 'no-such-model'"):
        transcribe(silence_wav)
