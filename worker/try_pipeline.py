"""Phase 3 check: make khmer.srt from a video (subtitles only, no dubbing yet).

Run it inside the worker container (./worker is mounted at /app):
    docker compose run --rm worker python try_pipeline.py samples/test.webm
This writes samples/test-out/khmer.srt and samples/test-out/segments.json.
"""

import argparse
import logging
import sys
import time
from pathlib import Path

import pipeline
from stages import StageError


def show_progress(percent: int, message: str) -> None:
    """Print a progress milestone."""
    print(f"[{percent:3d}%] {message}", file=sys.stderr)


def main() -> int:
    """Run the subtitle-only pipeline and print where the SRT went."""
    parser = argparse.ArgumentParser(description="Make a Khmer .srt subtitle file from a video.")
    parser.add_argument("video", type=Path, help="path inside the container, e.g. samples/test.webm")
    parser.add_argument("--out", type=Path, help="output folder (default: <video name>-out next to the video)")
    args = parser.parse_args()
    logging.basicConfig(level=logging.WARNING, format="%(levelname)s %(name)s: %(message)s")
    out = args.out or args.video.with_name(f"{args.video.stem}-out")

    started = time.monotonic()
    try:
        result = pipeline.run(out, args.video, "female", False, show_progress)
    except StageError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    print(f"Language: {result['language']}   Segments: {result['segments']}   Took: {time.monotonic() - started:.0f} s")
    print(f"Subtitles: {result['srt']}")
    print("Machine translation: a Khmer speaker must review it before use.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
