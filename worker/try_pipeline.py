"""Phase 3 and 4 check: make khmer.srt, and with --dub also dubbed.mp4, from a video.

Run it inside the worker container (./worker is mounted at /app):
    docker compose run --rm worker python try_pipeline.py samples/test.webm
    docker compose run --rm worker python try_pipeline.py samples/test.webm --dub --voice male
This writes samples/test-out/khmer.srt, segments.json and, with --dub, dubbed.mp4.
"""

import argparse
import json
import logging
import sys
import time
from pathlib import Path

import pipeline
from stages import StageError

CUT_REPORT_MS = 100  # cuts shorter than this are just the rounding of the speed-up


def show_progress(percent: int, message: str) -> None:
    """Print a progress milestone."""
    print(f"[{percent:3d}%] {message}", file=sys.stderr)


def report_timing(segments: list[dict]) -> None:
    """Print how much the clips were sped up, which were cut, and any that overlap the next segment."""
    placed = [seg for seg in segments if "speed" in seg]
    fastest = max(placed, key=lambda seg: seg["speed"], default=None)
    sped = [seg for seg in placed if seg["speed"] > 1]
    print(f"Clips placed: {len(placed)}   sped up: {len(sped)}", end="")
    print(f"   fastest: x{fastest['speed']:.2f} (segment {fastest['id']})" if fastest else "")
    for seg in placed:
        if seg["cut_ms"] >= CUT_REPORT_MS:
            print(f"  segment {seg['id']}: {seg['cut_ms'] / 1000:.1f} s of speech cut even at x{seg['speed']:.2f}")
    overlaps = [(a, b) for a, b in zip(segments, segments[1:]) if a.get("dub_end", 0) > b["start"]]
    for a, b in overlaps:
        print(f"  OVERLAP: segment {a['id']} voice ends at {a['dub_end']:.2f} s, segment {b['id']} starts at {b['start']:.2f} s")
    print(f"Clips that overlap the next segment: {len(overlaps)}")


def main() -> int:
    """Run the pipeline and print where the results went."""
    parser = argparse.ArgumentParser(description="Make Khmer subtitles, and optionally a Khmer dubbed video.")
    parser.add_argument("video", type=Path, help="path inside the container, e.g. samples/test.webm")
    parser.add_argument("--out", type=Path, help="output folder (default: <video name>-out next to the video)")
    parser.add_argument("--dub", action="store_true", help="also make dubbed.mp4 with a Khmer voice")
    parser.add_argument("--voice", choices=["female", "male"], default="female", help="Khmer voice (default female)")
    args = parser.parse_args()
    logging.basicConfig(level=logging.WARNING, format="%(levelname)s %(name)s: %(message)s")
    out = args.out or args.video.with_name(f"{args.video.stem}-out")

    started = time.monotonic()
    try:
        result = pipeline.run(out, args.video, args.voice, args.dub, show_progress)
    except StageError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    print(f"Language: {result['language']}   Segments: {result['segments']}   Took: {time.monotonic() - started:.0f} s")
    print(f"Subtitles: {result['srt']}")
    if result["video"]:
        print(f"Dubbed video: {result['video']}")
        report_timing(json.loads((out / "segments.json").read_text(encoding="utf-8")))
    print("Machine translation and AI voice: a Khmer speaker must review them before use.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
