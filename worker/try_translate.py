"""Phase 2 check: print the original and the Khmer text of the first segments of a video.

Run it inside the worker container (./worker is mounted at /app):
    docker compose run --rm worker python try_translate.py samples/test.webm
Save the sample for a Khmer speaker to review:
    docker compose run --rm -T worker python try_translate.py samples/test.webm > worker/samples/review.txt
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
from stages.translate import translate
from try_transcribe import clock


def main() -> int:
    """Transcribe the video, translate the first segments and print each pair."""
    parser = argparse.ArgumentParser(description="Print original and Khmer text for the first segments of a video.")
    parser.add_argument("video", type=Path, help="path inside the container, e.g. samples/test.webm")
    parser.add_argument("--limit", type=int, default=20, help="number of segments to translate (default 20)")
    args = parser.parse_args()
    logging.basicConfig(level=logging.WARNING, format="%(levelname)s %(name)s: %(message)s")

    with tempfile.TemporaryDirectory() as tmp:
        try:
            extract_audio(args.video, Path(tmp) / "audio.wav")
            print("Transcribing...", file=sys.stderr)
            segments, language = transcribe(Path(tmp) / "audio.wav")
            segments = segments[: args.limit]
            print(f"Translating {len(segments)} segments (the first run downloads the model)...", file=sys.stderr)
            started = time.monotonic()
            translate(segments, language)
        except StageError as exc:
            print(f"Error: {exc}", file=sys.stderr)
            return 1

    print(f"Language: {language}   Segments: {len(segments)}   Translation took: {time.monotonic() - started:.0f} s")
    print("Machine translation: a Khmer speaker must review it before use.\n")
    for seg in segments:
        print(f"{seg['id']:>3}  {clock(seg['start'])}  {language}  {seg['text']}")
        print(f"{'':>3}  {'':12}  km  {seg['km']}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
