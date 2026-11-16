"""Stage 2b: find_speakers, the clustering and the pitch guess. The voice-print model runs only in `models` tests."""

import wave
from pathlib import Path

import numpy as np
import pytest

import config
from stages import speakers
from stages.speakers import cluster, find_speakers, typical_pitch
from tests.conftest import ffmpeg

RATE = 16000


def sine(frequency: float, seconds: float) -> np.ndarray:
    """A sine wave at 16 kHz with a few harmonics, roughly like a voiced sound."""
    t = np.arange(int(seconds * RATE)) / RATE
    return sum(0.3 / k * np.sin(2 * np.pi * frequency * k * t) for k in (1, 2, 3)).astype(np.float32)


def write_wav(path: Path, samples: np.ndarray) -> Path:
    """Write float samples as a mono 16 kHz 16-bit WAV."""
    with wave.open(str(path), "wb") as wav:
        wav.setnchannels(1)
        wav.setsampwidth(2)
        wav.setframerate(RATE)
        wav.writeframes((np.clip(samples, -1, 1) * 32767).astype("<i2").tobytes())
    return path


def unit(*values: float) -> np.ndarray:
    """A unit-length vector."""
    vector = np.array(values, dtype=float)
    return vector / np.linalg.norm(vector)


def test_cluster_groups_alike_prints() -> None:
    prints = np.stack([unit(1, 0, 0), unit(0, 1, 0), unit(0.98, 0.05, 0), unit(0, 0, 1), unit(0.05, 0.99, 0)])
    labels = cluster(prints, threshold=0.85, max_groups=6)
    assert labels[0] == labels[2] and labels[1] == labels[4]
    assert len({labels[0], labels[1], labels[3]}) == 3


def test_cluster_keeps_to_the_largest_number_of_speakers() -> None:
    prints = np.stack([unit(1, 0, 0), unit(0, 1, 0), unit(0, 0, 1), unit(0.7, 0.7, 0)])
    assert len(set(cluster(prints, threshold=0.99, max_groups=2))) == 2


@pytest.mark.parametrize("frequency", [110.0, 220.0])
def test_typical_pitch_finds_the_frequency(frequency: float) -> None:
    assert typical_pitch(sine(frequency, 1.0)) == pytest.approx(frequency, rel=0.05)


def test_silence_has_no_pitch() -> None:
    assert typical_pitch(np.zeros(RATE, dtype=np.float32)) is None


@pytest.fixture
def fake_prints(monkeypatch: pytest.MonkeyPatch) -> list[int]:
    """Replace the voice-print model: a line's print points one way for a low voice, another for a high one."""
    heard: list[int] = []

    def voice_prints(audio: np.ndarray, segments: list[dict], on_step: object) -> np.ndarray:
        heard.extend(seg["id"] for seg in segments)
        return np.stack([unit(1, 0) if typical_pitch(speakers._cut(audio, seg)) < 165 else unit(0, 1) for seg in segments])

    monkeypatch.setattr(speakers, "_voice_prints", voice_prints)
    monkeypatch.setattr(config, "SPEAKER_THRESHOLD", 0.85)
    monkeypatch.setattr(config, "MAX_SPEAKERS", 6)
    return heard


def test_lines_get_speakers_in_order_and_a_guessed_voice(fake_prints: list[int], tmp_path: Path) -> None:
    # high voice 0-3 s, low 3-5 s, a short high line 5-5.5 s, low 5.5-8.5 s
    audio = np.concatenate([sine(220, 3), sine(110, 2), sine(220, 0.5), sine(110, 3)])
    wav = write_wav(tmp_path / "a.wav", audio)
    segments = [{"id": 0, "start": 0.0, "end": 3.0}, {"id": 1, "start": 3.0, "end": 5.0},
                {"id": 2, "start": 5.0, "end": 5.5}, {"id": 3, "start": 5.5, "end": 8.5}]

    found = find_speakers(wav, segments)

    assert [seg["speaker"] for seg in segments][:2] == [0, 1]
    assert segments[3]["speaker"] == 1
    assert fake_prints == [0, 1, 3], "a line under 1 s gets no voice print"
    assert segments[2]["speaker"] in (0, 1), "it joins a speaker next to it"
    assert found[0]["gender"] == "female" and found[1]["gender"] == "male"
    assert sum(spk["lines"] for spk in found) == 4


def test_a_stray_short_group_joins_a_real_speaker(fake_prints: list[int], tmp_path: Path) -> None:
    # 1.2 s of a high voice is too little to be a speaker of its own next to 6 s of a low one
    wav = write_wav(tmp_path / "a.wav", np.concatenate([sine(110, 3), sine(220, 1.2), sine(110, 3)]))
    segments = [{"id": 0, "start": 0.0, "end": 3.0}, {"id": 1, "start": 3.0, "end": 4.2}, {"id": 2, "start": 4.2, "end": 7.2}]

    found = find_speakers(wav, segments)

    assert [seg["speaker"] for seg in segments] == [0, 0, 0]
    assert len(found) == 1


def test_one_long_line_is_one_speaker(fake_prints: list[int], tmp_path: Path) -> None:
    wav = write_wav(tmp_path / "a.wav", sine(110, 3))
    segments = [{"id": 0, "start": 0.0, "end": 3.0}]
    assert find_speakers(wav, segments) == [{"id": 0, "gender": "male", "lines": 1}]
    assert fake_prints == [], "nothing to compare, so no model"


@pytest.mark.models
def test_real_voices_are_told_apart(tmp_path: Path) -> None:
    """Two women and a man (edge-tts English voices) taking turns. Needs internet and the 400 MB model."""
    turns = [("en-US-AriaNeural", "Good morning everyone, and thank you for coming to the meeting today."),
             ("en-US-GuyNeural", "I would like to start with the sales numbers from last month."),
             ("en-GB-SoniaNeural", "Before we begin, can somebody share the slides on the big screen?"),
             ("en-US-GuyNeural", "Of course, give me one moment while I open the presentation."),
             ("en-US-AriaNeural", "While we wait, let us look at the agenda for this afternoon.")]
    segments, parts, start = [], [], 0.0
    for n, (voice, text) in enumerate(turns):
        mp3 = tmp_path / f"{n}.mp3"
        import edge_tts  # only this test needs the network
        try:
            edge_tts.Communicate(text, voice).save_sync(str(mp3))
        except Exception as exc:
            pytest.skip(f"edge-tts is unreachable: {exc}")
        part = tmp_path / f"{n}.wav"
        ffmpeg("-i", str(mp3), "-ac", "1", "-ar", str(RATE), "-c:a", "pcm_s16le", str(part))
        with wave.open(str(part), "rb") as w:
            samples = np.frombuffer(w.readframes(w.getnframes()), dtype="<i2").astype(np.float32) / 32768
        segments.append({"id": n, "start": start, "end": start + len(samples) / RATE})
        parts.append(samples)
        start += len(samples) / RATE
    wav = write_wav(tmp_path / "all.wav", np.concatenate(parts))

    found = find_speakers(wav, segments)

    assert [seg["speaker"] for seg in segments] == [0, 1, 2, 1, 0]
    assert [spk["gender"] for spk in found] == ["female", "male", "female"]


