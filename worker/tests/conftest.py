"""Short sample files for the stage tests, generated once per test run with FFmpeg."""

import re
import subprocess
from pathlib import Path

import pytest

SPEECH_TEXT = "Hello and welcome. Today we are testing a tool that translates videos into Khmer."
FIRST_SENTENCE = "Good morning. This is the first sentence of our short test."
SECOND_SENTENCE = "After a long pause, here is the second sentence."
SPEECH_DELAY_MS = 1500


def ffmpeg(*args: str) -> None:
    """Run FFmpeg quietly with the given arguments."""
    subprocess.run(["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", *args], check=True)


def tone(path: Path, seconds: float, frequency: int = 440) -> str:
    """Write a WAV 24 kHz mono tone (the clip format of Stage 4) and return its path as a string."""
    ffmpeg("-f", "lavfi", "-i", f"sine=frequency={frequency}:duration={seconds}:sample_rate=24000",
           "-ac", "1", "-c:a", "pcm_s16le", str(path))
    return str(path)


def probe(path: Path, *entries: str) -> list[str]:
    """Return ffprobe's values for `-show_entries` items, one CSV line per stream or packet."""
    args = ["ffprobe", "-v", "error", *entries, "-of", "csv=p=0", str(path)]
    return subprocess.run(args, check=True, capture_output=True, text=True).stdout.split()


@pytest.fixture(scope="session")
def samples(tmp_path_factory: pytest.TempPathFactory) -> Path:
    """Folder that holds the generated samples."""
    return tmp_path_factory.mktemp("samples")


@pytest.fixture(scope="session")
def tone_video(samples: Path) -> Path:
    """2 second MP4 with a test picture and a stereo 440 Hz tone."""
    path = samples / "tone.mp4"
    ffmpeg("-f", "lavfi", "-i", "testsrc=size=160x120:rate=10", "-f", "lavfi", "-i", "sine=frequency=440",
           "-t", "2", "-c:v", "mpeg4", "-c:a", "aac", "-ac", "2", str(path))
    return path


@pytest.fixture(scope="session")
def silent_video(samples: Path) -> Path:
    """1 second MP4 with no sound track at all."""
    path = samples / "silent.mp4"
    ffmpeg("-f", "lavfi", "-i", "testsrc=size=160x120:rate=10", "-t", "1", "-c:v", "mpeg4", "-an", str(path))
    return path


@pytest.fixture(scope="session")
def silence_wav(samples: Path) -> Path:
    """3 second mono 16 kHz WAV of digital silence."""
    path = samples / "silence.wav"
    ffmpeg("-f", "lavfi", "-i", "anullsrc=r=16000:cl=mono", "-t", "3", "-c:a", "pcm_s16le", str(path))
    return path


@pytest.fixture(scope="session")
def speech_video(samples: Path) -> Path:
    """MP4 with English speech that starts after 1.5 s of silence.

    The voice is made with edge-tts, so this needs internet; tests that use it are skipped offline.
    """
    mp3 = say(SPEECH_TEXT, samples / "speech.mp3")
    path = samples / "speech.mp4"
    ffmpeg("-f", "lavfi", "-i", "color=c=black:size=160x120:rate=10", "-i", str(mp3),
           "-af", f"adelay={SPEECH_DELAY_MS}:all=1", "-shortest", "-c:v", "mpeg4", "-c:a", "aac", str(path))
    return path


@pytest.fixture(scope="session")
def two_sentence_video(samples: Path) -> tuple[Path, tuple[float, float], tuple[float, float]]:
    """MP4 with two English sentences 2.5 s apart after 1.5 s of silence, and when each is spoken.

    The (start, end) times come from FFmpeg's silencedetect, which shares nothing with Whisper or
    the Silero VAD, so tests can check the segment times against them. Needs internet (edge-tts).
    """
    first = say(FIRST_SENTENCE, samples / "first.mp3")
    second = say(SECOND_SENTENCE, samples / "second.mp3")
    wav = samples / "two_sentences.wav"
    ffmpeg("-i", str(first), "-i", str(second), "-filter_complex",
           f"[0:a]adelay={SPEECH_DELAY_MS}:all=1,apad=pad_dur=2.5[a];[a][1:a]concat=n=2:v=0:a=1,apad=pad_dur=1",
           "-ac", "1", "-ar", "16000", str(wav))

    spans = speech_spans(wav)
    gap = max(range(len(spans) - 1), key=lambda i: spans[i + 1][0] - spans[i][1])  # the 2.5 s pause
    path = samples / "two_sentences.mp4"
    ffmpeg("-f", "lavfi", "-i", "color=c=black:size=160x120:rate=10", "-i", str(wav),
           "-shortest", "-c:v", "mpeg4", "-c:a", "aac", str(path))
    return path, (spans[0][0], spans[gap][1]), (spans[gap + 1][0], spans[-1][1])


def say(text: str, mp3: Path) -> Path:
    """Write English speech to `mp3` with edge-tts, or skip the test when it is unreachable."""
    import edge_tts

    try:
        edge_tts.Communicate(text, "en-US-AriaNeural").save_sync(str(mp3))
    except Exception as exc:  # no network, or the unofficial service changed
        pytest.skip(f"edge-tts is unreachable, so the speech sample cannot be made: {exc}")
    return mp3


def speech_spans(wav: Path) -> list[tuple[float, float]]:
    """Return the (start, end) seconds of sound between pauses of 0.3 s or more, using silencedetect."""
    result = subprocess.run(["ffmpeg", "-nostdin", "-hide_banner", "-i", str(wav), "-af",
                             "silencedetect=noise=-45dB:d=0.3", "-f", "null", "-"],
                            check=True, capture_output=True, text=True)
    starts = [float(x) for x in re.findall(r"silence_start: ([\d.]+)", result.stderr)]
    ends = [float(x) for x in re.findall(r"silence_end: ([\d.]+)", result.stderr)]
    return list(zip(ends, starts[1:]))  # the file starts and ends with silence
