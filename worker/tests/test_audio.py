"""Stage 1: extract_audio."""

import wave
from pathlib import Path

import pytest

import config
from stages import StageError, audio
from stages.audio import extract_audio


def test_extracts_mono_16k_wav(tone_video: Path, tmp_path: Path) -> None:
    wav_path = tmp_path / "new" / "audio.wav"
    duration = extract_audio(tone_video, wav_path)

    assert duration == pytest.approx(2.0, abs=0.1)
    with wave.open(str(wav_path), "rb") as wav:
        assert wav.getnchannels() == 1
        assert wav.getframerate() == 16000
        assert wav.getsampwidth() == 2
        assert wav.getnframes() / wav.getframerate() == pytest.approx(2.0, abs=0.1)


def test_check_video_returns_length_without_extracting(tone_video: Path, tmp_path: Path) -> None:
    assert audio.check_video(tone_video) == pytest.approx(2.0, abs=0.1)
    with pytest.raises(StageError, match="missing"):
        audio.check_video(tmp_path / "nothing.mp4")


def test_rejects_video_longer_than_limit(tone_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "MAX_VIDEO_MINUTES", 1 / 60)  # 1 second
    with pytest.raises(StageError, match="the limit is"):
        extract_audio(tone_video, tmp_path / "audio.wav")
    assert not (tmp_path / "audio.wav").exists(), "length must be checked before extracting"


def test_checks_length_after_extracting_when_file_has_none(
    tone_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setattr(audio, "_probe", lambda path: None)
    assert extract_audio(tone_video, tmp_path / "audio.wav") == pytest.approx(2.0, abs=0.1)

    monkeypatch.setattr(config, "MAX_VIDEO_MINUTES", 1 / 60)
    with pytest.raises(StageError, match="the limit is"):
        extract_audio(tone_video, tmp_path / "audio.wav")


def test_missing_file(tmp_path: Path) -> None:
    with pytest.raises(StageError, match="missing"):
        extract_audio(tmp_path / "nothing.mp4", tmp_path / "audio.wav")


def test_unreadable_file(tmp_path: Path) -> None:
    fake = tmp_path / "fake.mp4"
    fake.write_text("this is not a video")
    with pytest.raises(StageError, match="not a video"):
        extract_audio(fake, tmp_path / "audio.wav")


def test_video_without_sound(silent_video: Path, tmp_path: Path) -> None:
    with pytest.raises(StageError, match="no sound track"):
        extract_audio(silent_video, tmp_path / "audio.wav")


def test_ffmpeg_not_installed(tone_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PATH", "")
    with pytest.raises(StageError, match="FFmpeg is not installed"):
        extract_audio(tone_video, tmp_path / "audio.wav")
