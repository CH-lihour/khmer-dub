"""Runs the stages for one job in order: audio, transcribe, translate, subtitles, then voice and mix.

run() does it all in one go. A job that a person reviews first calls prepare(), waits for the
edits to the Khmer text (saved with save_segments), and then calls finish().
"""

import json
import logging
from collections.abc import Callable
from pathlib import Path

from stages.audio import extract_audio
from stages.mix import build_track, merge, write_srt
from stages.transcribe import transcribe
from stages.translate import translate
from stages.tts import synth_all

log = logging.getLogger(__name__)

Progress = Callable[[int, str], None]
VOICE_FIELDS = ("clip", "speed", "cut_ms", "dub_end")  # what the voice and mix stages add to a segment


def run(job_dir: str | Path, video_path: str | Path, gender: str, dub: bool, progress: Progress,
        original_volume: float | None = None) -> dict:
    """Process one video inside `job_dir` and return {"language", "segments", "srt", "video"}.

    Reports the API contract's milestones through `progress(percent, message)`: 5 extract audio,
    15 transcribe, 45 translate, 60 voice, 85 mix and merge, 100 done. With dub=False it stops
    after the SRT and "video" is None. `gender` ("female" or "male") picks the Khmer voice, and
    `original_volume` (0 to 1) how loud the original sound plays under it; None uses
    ORIGINAL_VOLUME. Stage failures raise StageError with a message the user can act on.
    """
    prepared = prepare(job_dir, video_path, progress)
    finished = finish(job_dir, video_path, gender, dub, prepared["duration"], progress, original_volume)
    return {"language": prepared["language"], **finished}


def prepare(job_dir: str | Path, video_path: str | Path, progress: Progress) -> dict:
    """Make the Khmer text of a video and save it as segments.json in `job_dir`.

    Returns {"language", "duration"}, with the video's length in seconds for finish().
    """
    job_dir = Path(job_dir)
    job_dir.mkdir(parents=True, exist_ok=True)
    wav = job_dir / "audio.wav"

    progress(5, "Extracting audio")
    duration = extract_audio(video_path, wav)

    progress(15, "Transcribing speech")
    segments, language = transcribe(wav)

    progress(45, "Translating to Khmer")
    translate(segments, language)
    save_segments(job_dir, segments)
    return {"language": language, "duration": duration}


def finish(job_dir: str | Path, video_path: str | Path, gender: str, dub: bool, duration: float,
           progress: Progress, original_volume: float | None = None) -> dict:
    """Turn the saved segments.json into the results and return {"segments", "srt", "video"}.

    An empty Khmer line gets no subtitle and no voice. With dub=False it stops after the SRT and
    "video" is None. The results of an earlier run are replaced.
    """
    job_dir = Path(job_dir)
    segments = load_segments(job_dir)
    for seg in segments:  # from an earlier run: a line emptied since then must not keep its old voice
        for key in VOICE_FIELDS:
            seg.pop(key, None)
    srt = job_dir / "khmer.srt"
    write_srt(segments, srt)

    video = None
    if dub:
        progress(60, "Making the Khmer voice")
        synth_all(segments, job_dir / "clips", gender)
        progress(85, "Mixing and merging")
        dub_wav = build_track(segments, round(duration * 1000), job_dir)
        video = merge(video_path, dub_wav, job_dir / "dubbed.mp4", original_volume)

    # Kept for debugging and review: each segment's times, original and Khmer text, and voice timing.
    save_segments(job_dir, segments)
    progress(100, "Done")
    log.info("Job %s done: %d segments", job_dir.name, len(segments))
    return {"segments": len(segments), "srt": srt, "video": video}


def load_segments(job_dir: str | Path) -> list[dict]:
    """Read the segments saved in `job_dir`."""
    return json.loads((Path(job_dir) / "segments.json").read_text(encoding="utf-8"))


def save_segments(job_dir: str | Path, segments: list[dict]) -> None:
    """Write the segments to segments.json in `job_dir`."""
    (Path(job_dir) / "segments.json").write_text(json.dumps(segments, ensure_ascii=False, indent=1), encoding="utf-8")
