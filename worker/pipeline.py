"""Runs the stages for one job in order: audio, transcribe, translate, subtitles (then voice and mix)."""

import json
import logging
from collections.abc import Callable
from pathlib import Path

from stages.audio import extract_audio
from stages.mix import write_srt
from stages.transcribe import transcribe
from stages.translate import translate

log = logging.getLogger(__name__)

Progress = Callable[[int, str], None]


def run(job_dir: str | Path, video_path: str | Path, gender: str, dub: bool, progress: Progress) -> dict:
    """Process one video inside `job_dir` and return {"language", "segments", "srt", "video"}.

    Reports the API contract's milestones through `progress(percent, message)`: 5 extract audio,
    15 transcribe, 45 translate, 60 voice, 85 mix and merge, 100 done. With dub=False it stops
    after the SRT and "video" is None. `gender` picks the Khmer voice when dubbing. Stage
    failures raise StageError with a message the user can act on.
    """
    if dub:
        raise NotImplementedError("Dubbing (dub=True) is added in Phase 4")
    job_dir = Path(job_dir)
    job_dir.mkdir(parents=True, exist_ok=True)
    wav = job_dir / "audio.wav"

    progress(5, "Extracting audio")
    extract_audio(video_path, wav)

    progress(15, "Transcribing speech")
    segments, language = transcribe(wav)

    progress(45, "Translating to Khmer")
    translate(segments, language)
    srt = job_dir / "khmer.srt"
    write_srt(segments, srt)
    # Kept for debugging and review: the original and Khmer text of every segment with its times.
    (job_dir / "segments.json").write_text(json.dumps(segments, ensure_ascii=False, indent=1), encoding="utf-8")

    progress(100, "Done")
    log.info("Job %s done: %d segments from %s", job_dir.name, len(segments), language)
    return {"language": language, "segments": len(segments), "srt": srt, "video": None}
