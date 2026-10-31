"""Short sample files for the stage tests, generated once per test run with FFmpeg."""

import subprocess
from pathlib import Path

import pytest

SPEECH_TEXT = "Hello and welcome. Today we are testing a tool that translates videos into Khmer."
SPEECH_DELAY_MS = 1500


def ffmpeg(*args: str) -> None:
    """Run FFmpeg quietly with the given arguments."""
    subprocess.run(["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", *args], check=True)


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
    import edge_tts

    mp3 = samples / "speech.mp3"
    try:
        edge_tts.Communicate(SPEECH_TEXT, "en-US-AriaNeural").save_sync(str(mp3))
    except Exception as exc:  # no network, or the unofficial service changed
        pytest.skip(f"edge-tts is unreachable, so the speech sample cannot be made: {exc}")

    path = samples / "speech.mp4"
    ffmpeg("-f", "lavfi", "-i", "color=c=black:size=160x120:rate=10", "-i", str(mp3),
           "-af", f"adelay={SPEECH_DELAY_MS}:all=1", "-shortest", "-c:v", "mpeg4", "-c:a", "aac", str(path))
    return path
