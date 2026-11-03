"""Stage 1: extract the sound of the uploaded video as a mono 16 kHz WAV with FFmpeg."""

import json
import logging
import subprocess
import wave
from pathlib import Path

import config
from stages import StageError

log = logging.getLogger(__name__)

SAMPLE_RATE = 16000
FFMPEG_MISSING = "FFmpeg is not installed in the worker. Rebuild it with: docker compose build worker"


def extract_audio(video_path: str | Path, wav_path: str | Path) -> float:
    """Write the video's sound to `wav_path` as mono 16 kHz 16-bit PCM and return the length in seconds.

    The length is checked with ffprobe first. Raises StageError if the file is missing, cannot be
    read, has no sound track, is longer than MAX_VIDEO_MINUTES, or FFmpeg is not installed.
    """
    video_path, wav_path = Path(video_path), Path(wav_path)
    duration = check_video(video_path)

    wav_path.parent.mkdir(parents=True, exist_ok=True)
    _run(
        ["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", "-i", str(video_path),
         "-vn", "-ac", "1", "-ar", str(SAMPLE_RATE), "-c:a", "pcm_s16le", str(wav_path)],
        "Could not read the sound of this video. Try converting it to MP4 first.",
    )

    if duration is None:  # some files (e.g. browser-recorded WebM) do not store their length
        duration = _wav_seconds(wav_path)
        _check_length(duration)
    return duration


def check_video(video_path: str | Path) -> float | None:
    """Check with ffprobe that the file is a readable video with sound, within MAX_VIDEO_MINUTES.

    Returns the stored length in seconds, or None when the file does not store one (then
    extract_audio measures it). The API calls this on upload so bad files are rejected at once.
    Raises StageError like extract_audio.
    """
    video_path = Path(video_path)
    if not video_path.is_file():
        raise StageError("The video file is missing. Please upload it again.")
    duration = _probe(video_path)
    if duration is not None:
        _check_length(duration)
    return duration


def _probe(video_path: Path) -> float | None:
    """Return the stored length in seconds, or None if the file has none. Rejects files without sound."""
    result = _run(
        ["ffprobe", "-v", "error", "-print_format", "json", "-show_format", "-show_streams", str(video_path)],
        "This file is not a video FFmpeg can read. Please upload an MP4, MOV, MKV or WebM file.",
    )
    info = json.loads(result.stdout or "{}")
    if not any(stream.get("codec_type") == "audio" for stream in info.get("streams", [])):
        raise StageError("The video has no sound track, so there is nothing to translate.")
    try:
        return float(info["format"]["duration"])
    except (KeyError, ValueError):
        return None


def _check_length(seconds: float) -> None:
    """Raise StageError if the video is longer than MAX_VIDEO_MINUTES."""
    limit = config.MAX_VIDEO_MINUTES
    if seconds > limit * 60:
        raise StageError(f"The video is {seconds / 60:.1f} minutes long; the limit is {limit:g} minutes.")


def _wav_seconds(wav_path: Path) -> float:
    """Return the length of a WAV file in seconds."""
    with wave.open(str(wav_path), "rb") as wav:
        return wav.getnframes() / wav.getframerate()


def _run(args: list[str], user_message: str) -> subprocess.CompletedProcess[str]:
    """Run an FFmpeg tool. On failure, log its stderr and raise StageError(user_message)."""
    try:
        return subprocess.run(args, check=True, capture_output=True, text=True, encoding="utf-8", errors="replace")
    except FileNotFoundError as exc:
        raise StageError(FFMPEG_MISSING) from exc
    except subprocess.CalledProcessError as exc:
        log.error("%s failed (exit %s): %s", args[0], exc.returncode, exc.stderr.strip())
        raise StageError(user_message) from exc
