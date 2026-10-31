"""Phase 1 check: print the timed segments and the detected language of a video.

Run it inside the worker container (./worker is mounted at /app):
    docker compose run --rm worker python try_transcribe.py samples/test.mp4
"""

import argparse
import logging
import sys
import tempfile
import time
from pathlib import Path

from stages import StageError
from stages.audio import extract_audio
from stages.transcribe import transcribe


def clock(seconds: float) -> str:
    """Format seconds as HH:MM:SS.mmm."""
    ms = round(seconds * 1000)
    return f"{ms // 3_600_000:02d}:{ms // 60_000 % 60:02d}:{ms // 1000 % 60:02d}.{ms % 1000:03d}"


def main() -> int:
    """Extract the audio, transcribe it and print one line per segment."""
    parser = argparse.ArgumentParser(description="Print the timed speech segments of a video.")
    parser.add_argument("video", type=Path, help="path inside the container, e.g. samples/test.mp4")
    args = parser.parse_args()
    logging.basicConfig(level=logging.WARNING, format="%(levelname)s %(name)s: %(message)s")

    with tempfile.TemporaryDirectory() as tmp:
        try:
            duration = extract_audio(args.video, Path(tmp) / "audio.wav")
            print(f"Transcribing {duration:.1f} s of audio (the first run downloads the model)...", file=sys.stderr)
            started = time.monotonic()
            segments, language = transcribe(Path(tmp) / "audio.wav")
        except StageError as exc:
            print(f"Error: {exc}", file=sys.stderr)
            return 1

    print(f"Language: {language}   Segments: {len(segments)}   Took: {time.monotonic() - started:.0f} s")
    for seg in segments:
        print(f"{seg['id']:>3}  {clock(seg['start'])} --> {clock(seg['end'])}  {seg['text']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
