"""Stage 4: synth and synth_all. edge-tts is faked except in the tests marked `models`."""

import wave
from pathlib import Path

import pytest

import config
from stages import StageError, tts
from stages.tts import synth, synth_all
from tests.conftest import ffmpeg


def wav_info(path: Path) -> tuple[int, int, float]:
    """Return (channels, sample rate, seconds) of a WAV file."""
    with wave.open(str(path), "rb") as wav:
        return wav.getnchannels(), wav.getframerate(), wav.getnframes() / wav.getframerate()


@pytest.fixture
def fake_edge(monkeypatch: pytest.MonkeyPatch) -> list[tuple[str, str]]:
    """Make edge-tts return a 1 s tone with silence around it, like the real service; record (text, voice)."""
    calls: list[tuple[str, str]] = []

    class FakeCommunicate:
        def __init__(self, text: str, voice: str) -> None:
            calls.append((text, voice))

        def save_sync(self, path: str) -> None:
            ffmpeg("-f", "lavfi", "-i", "sine=frequency=300:duration=1:sample_rate=24000",
                   "-af", "adelay=200,apad=pad_dur=0.8", "-f", "mp3", path)

    monkeypatch.setattr(tts.edge_tts, "Communicate", FakeCommunicate)
    monkeypatch.setattr(config, "TTS_ENGINE", "edge")
    return calls


@pytest.fixture
def no_sleep(monkeypatch: pytest.MonkeyPatch) -> None:
    """Skip the waits between edge-tts retries."""
    monkeypatch.setattr(tts.time, "sleep", lambda seconds: None)


def failing_edge(monkeypatch: pytest.MonkeyPatch, failures: int, error: Exception | None = None) -> list[int]:
    """Make edge-tts fail `failures` times, then work; return the list of attempts."""
    attempts: list[int] = []

    class FlakyCommunicate:
        def __init__(self, text: str, voice: str) -> None:
            pass

        def save_sync(self, path: str) -> None:
            attempts.append(1)
            if len(attempts) <= failures:
                raise error or ConnectionError("network is unreachable")
            ffmpeg("-f", "lavfi", "-i", "sine=duration=0.5", "-f", "mp3", path)

    monkeypatch.setattr(tts.edge_tts, "Communicate", FlakyCommunicate)
    monkeypatch.setattr(config, "TTS_ENGINE", "edge")
    return attempts


def test_clip_is_24k_mono_wav_with_silence_trimmed(fake_edge: list, tmp_path: Path) -> None:
    out = synth("សួស្តី", tmp_path / "0.wav", "female")

    channels, rate, seconds = wav_info(out)
    assert (channels, rate) == (1, 24000)
    assert seconds == pytest.approx(1.0, abs=0.12), "the 0.2 s before and 0.8 s after should be cut"
    assert fake_edge == [("សួស្តី", "km-KH-SreymomNeural")]
    assert sorted(tmp_path.iterdir()) == [out], "the raw edge-tts file must be removed"


def test_male_voice(fake_edge: list, tmp_path: Path) -> None:
    synth("សួស្តី", tmp_path / "0.wav", "male")
    assert fake_edge[0][1] == "km-KH-PisethNeural"


def test_unknown_voice(tmp_path: Path) -> None:
    with pytest.raises(StageError, match="Unknown voice"):
        synth("សួស្តី", tmp_path / "0.wav", "child")


def test_unknown_engine(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "TTS_ENGINE", "cloud")
    with pytest.raises(StageError, match="Unknown TTS_ENGINE 'cloud'"):
        synth("សួស្តី", tmp_path / "0.wav", "female")


def test_edge_is_retried_twice(no_sleep: None, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    attempts = failing_edge(monkeypatch, failures=2)
    assert synth("សួស្តី", tmp_path / "0.wav", "female").exists()
    assert len(attempts) == 3


def test_edge_no_audio_is_retried(no_sleep: None, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    # The service sometimes sends no audio for an ordinary sentence that works on the next try.
    attempts = failing_edge(monkeypatch, failures=1, error=tts.edge_tts.exceptions.NoAudioReceived("No audio"))
    out = synth("ការ ពង្រីក ចំណេះ ដឹង", tmp_path / "0.wav", "female")
    assert len(attempts) == 2
    assert wav_info(out)[2] > 0.3


def test_a_line_the_voice_cannot_read_is_not_called_a_network_problem(no_sleep: None, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    attempts = failing_edge(monkeypatch, failures=99, error=tts.edge_tts.exceptions.NoAudioReceived("No audio"))
    with pytest.raises(StageError, match="could not read this line: සිංහල") as caught:
        synth("සිංහල", tmp_path / "0.wav", "female")
    assert "internet" not in str(caught.value)
    assert len(attempts) == 3


def test_text_with_nothing_to_say_is_silent_without_the_engine(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    attempts = failing_edge(monkeypatch, failures=99)
    out = synth(" ។ ", tmp_path / "0.wav", "female")
    assert attempts == []
    assert wav_info(out)[:2] == (1, 24000)
    assert wav_info(out)[2] < 0.2


def test_edge_unreachable_says_to_switch_to_mms(no_sleep: None, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    attempts = failing_edge(monkeypatch, failures=99)
    with pytest.raises(StageError, match="TTS_ENGINE=mms"):
        synth("សួស្តី", tmp_path / "0.wav", "female")
    assert len(attempts) == 3


def test_synth_all_skips_segments_without_khmer(fake_edge: list, tmp_path: Path) -> None:
    segments = [{"id": 0, "km": "សួស្តី"}, {"id": 1, "km": " "}, {"id": 2}]
    synth_all(segments, tmp_path / "clips", "female")

    assert segments[0]["clip"] == str(tmp_path / "clips" / "0.wav")
    assert Path(segments[0]["clip"]).exists()
    assert "clip" not in segments[1] and "clip" not in segments[2]
    assert len(fake_edge) == 1


@pytest.mark.models
def test_mms_offline_voice(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "TTS_ENGINE", "mms")
    channels, rate, seconds = wav_info(synth("យើង មាន ការងារ ដ៏ ធំ", tmp_path / "0.wav", "male"))
    assert (channels, rate) == (1, 24000)
    assert 0.5 < seconds < 5


@pytest.mark.models
def test_mms_text_it_cannot_read_gives_silence(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "TTS_ENGINE", "mms")
    out = synth("៥, ៤, ៣", tmp_path / "0.wav", "female")  # Khmer digits are not in the MMS alphabet
    assert wav_info(out)[2] < 0.2


@pytest.mark.models
def test_real_edge_khmer_voice(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "TTS_ENGINE", "edge")
    try:
        out = synth("យើង មាន ការងារ ដ៏ ធំ មួយ ដែល ត្រូវ ធ្វើ", tmp_path / "0.wav", "female")
    except StageError as exc:
        pytest.skip(f"edge-tts unreachable: {exc}")
    channels, rate, seconds = wav_info(out)
    assert (channels, rate) == (1, 24000)
    assert 1.5 < seconds < 3.2, "about 3.2 s with edge-tts' own silence, less once it is trimmed"
