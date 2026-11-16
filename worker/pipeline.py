"""Runs the stages for one job in order: audio, transcribe, translate, subtitles, then voice and mix.

run() does it all in one go. A job that a person reviews first calls prepare(), waits for the
edits to the Khmer text (saved with save_segments), and then calls finish().
"""

import json
import logging
from collections.abc import Callable
from pathlib import Path

import config
from stages.audio import extract_audio
from stages.mix import build_track, merge, write_srt
from stages.separate import separate as separate_speech
from stages.speakers import find_speakers
from stages.transcribe import transcribe
from stages.translate import translate
from stages.tts import cached_clip, synth_all

log = logging.getLogger(__name__)

Progress = Callable[..., None]  # progress(percent, message), or with (done, total) inside a stage
VOICE_FIELDS = ("clip", "speed", "cut_ms", "dub_end")  # what the voice and mix stages add to a segment
# Semitones for the 1st, 2nd, ... speaker with the same Khmer voice, so they sound apart.
PITCH_STEPS = (0, -2, 2, -3, 3, -1)


def run(job_dir: str | Path, video_path: str | Path, gender: str, dub: bool, progress: Progress,
        original_volume: float | None = None, prepared: dict | None = None,
        on_prepared: Callable[[dict], None] | None = None, language: str | None = None,
        separate: bool = False, speakers: bool = False, glossary: list[dict] | None = None,
        memory: list[dict] | None = None) -> dict:
    """Process one video inside `job_dir` and return {"language", "segments", "srt", "video"}.

    Reports the API contract's milestones through `progress(percent, message)`: 5 extract audio,
    15 transcribe, 45 translate, 60 voice, 85 mix and merge (with `separate`: 85 remove the
    original speech, 92 mix and merge), 100 done. Between them, the long stages add how far they
    are: `progress(percent, message, done, total)`. With dub=False it stops after the SRT and
    "video" is None. `gender` ("female" or "male") picks the Khmer voice, and `original_volume`
    (0 to 1) how loud the original sound plays under it; None uses ORIGINAL_VOLUME. `language` (a
    Whisper code) is the spoken language, or None to detect it. `separate` takes the speech out of
    the original sound first, keeping music and effects. `speakers` finds who speaks each line, and
    gives each speaker a voice of their own (see prepare). `glossary` and `memory` go to the
    translation (see stages.translate.translate). Stage failures raise StageError with a
    message the user can act on.

    Once the Khmer text is saved, `on_prepared({"language", "duration"})` is called. Passing that
    back as `prepared` skips straight to the voice, using the saved segments.json: this is how a
    job goes on after a restart.
    """
    if prepared is None:
        prepared = prepare(job_dir, video_path, progress, language, speakers, glossary=glossary, memory=memory)
        if on_prepared:
            on_prepared(prepared)
    finished = finish(job_dir, video_path, gender, dub, prepared["duration"], progress, original_volume, separate)
    return {"language": prepared["language"], **finished}


def prepare(job_dir: str | Path, video_path: str | Path, progress: Progress, language: str | None = None,
            speakers: bool = False, glossary: list[dict] | None = None, memory: list[dict] | None = None) -> dict:
    """Make the Khmer text of a video and save it as segments.json in `job_dir`.

    `language` is the spoken language as a Whisper code, or None to detect it. With `speakers`,
    each line also gets its `speaker`, and the speakers with their guessed voices are saved as
    speakers.json (progress 38, "Finding the speakers"). Returns {"language", "duration"}, with
    the video's length in seconds for finish().
    """
    job_dir = Path(job_dir)
    job_dir.mkdir(parents=True, exist_ok=True)
    wav = job_dir / "audio.wav"

    progress(5, "Extracting audio")
    duration = extract_audio(video_path, wav)

    progress(15, "Transcribing speech")
    segments, language = transcribe(wav, _step(progress, 15, 37 if speakers else 44, "Transcribing speech"), language)
    if speakers:
        progress(38, "Finding the speakers")
        save_speakers(job_dir, find_speakers(wav, segments, _step(progress, 38, 44, "Finding the speakers")))

    progress(45, "Translating to Khmer")
    translate(segments, language, _step(progress, 45, 49, "Translating to Khmer"), glossary=glossary, memory=memory)
    save_segments(job_dir, segments)
    return {"language": language, "duration": duration}


def finish(job_dir: str | Path, video_path: str | Path, gender: str, dub: bool, duration: float,
           progress: Progress, original_volume: float | None = None, separate: bool = False) -> dict:
    """Turn the saved segments.json into the results and return {"segments", "srt", "video"}.

    An empty Khmer line gets no subtitle and no voice. With dub=False it stops after the SRT and
    "video" is None. The results of an earlier run are replaced, except background.wav: the
    original sound without its speech is kept for the next run, as it takes long to make.
    """
    job_dir = Path(job_dir)
    segments = load_segments(job_dir)
    for seg in segments:  # from an earlier run: a line emptied since then must not keep its old voice
        for key in VOICE_FIELDS:
            seg.pop(key, None)
    srt = job_dir / "khmer.srt"
    cues = write_srt(segments, srt)

    video = None
    if dub:
        progress(60, "Making the Khmer voice")
        synth_all(segments, job_dir / "clips", gender, _step(progress, 60, 84, "Making the Khmer voice"),
                  voices=voice_map(load_speakers(job_dir)))
        background = None
        volume = config.ORIGINAL_VOLUME if original_volume is None else original_volume
        if separate and volume > 0:  # with the original sound off there is nothing to separate
            background = job_dir / "background.wav"
            if not background.is_file():
                progress(85, "Removing the original speech")
                separate_speech(video_path, background, _step(progress, 85, 91, "Removing the original speech"))
            progress(92, "Mixing and merging")
        else:
            progress(85, "Mixing and merging")
        dub_wav = build_track(segments, round(duration * 1000), job_dir)
        video = merge(video_path, dub_wav, job_dir / "dubbed.mp4", original_volume, srt if cues else None, background)
        prune_clips(job_dir / "clips", segments)

    # Kept for debugging and review: each segment's times, original and Khmer text, and voice timing.
    save_segments(job_dir, segments)
    progress(100, "Done")
    log.info("Job %s done: %d segments", job_dir.name, len(segments))
    return {"segments": len(segments), "srt": srt, "video": video}


def line_voice(job_dir: str | Path, text: str, gender: str, speaker: int | None = None,
               voices: dict[int, str] | None = None) -> Path:
    """The clip of one line's Khmer `text`, as finish() would voice it, made now unless it is kept already.

    `gender` is the job's voice; with a `speaker` of the job, that speaker's voice and pitch are used
    instead, with `voices` ({speaker id: gender}) as chosen on the review page but not saved yet.
    The clip stays in the job's clips folder, so saving the line uses it instead of making it again.
    """
    job_dir = Path(job_dir)
    speakers = [{**spk, "gender": (voices or {}).get(spk["id"], spk["gender"])} for spk in load_speakers(job_dir)]
    voice, pitch = voice_map(speakers).get(speaker, (gender, 0))
    return cached_clip(text, job_dir / "clips", voice, pitch)[0]


def prune_clips(folder: str | Path, segments: list[dict]) -> None:
    """Delete the clips in `folder` that no segment uses any more, and the sped-up copies of the mix."""
    used = {Path(seg["clip"]).name for seg in segments if seg.get("clip")}
    for clip in Path(folder).glob("*.wav"):
        if clip.name not in used:
            clip.unlink(missing_ok=True)


def _step(progress: Progress, first: int, last: int, message: str) -> Callable[[int, int], None]:
    """A callback for a long stage: `done` of `total` moves the progress from `first` up to `last`."""
    def step(done: int, total: int) -> None:
        progress(first + (last - first) * done // max(total, 1), message, done, total)

    return step


def voice_map(speakers: list[dict]) -> dict[int, tuple[str, int]]:
    """Each speaker's (gender, pitch): the voice they chose, moved by PITCH_STEPS so that speakers
    with the same voice sound apart."""
    voices, used = {}, {"female": 0, "male": 0}
    for speaker in speakers:
        gender = speaker["gender"]
        voices[speaker["id"]] = (gender, PITCH_STEPS[used[gender] % len(PITCH_STEPS)])
        used[gender] += 1
    return voices


def load_speakers(job_dir: str | Path) -> list[dict]:
    """Read the speakers saved in `job_dir`: [{"id", "gender", "lines"}], or [] for a job without them."""
    path = Path(job_dir) / "speakers.json"
    return json.loads(path.read_text(encoding="utf-8")) if path.is_file() else []


def save_speakers(job_dir: str | Path, speakers: list[dict]) -> None:
    """Write the speakers to speakers.json in `job_dir`."""
    (Path(job_dir) / "speakers.json").write_text(json.dumps(speakers, ensure_ascii=False, indent=1), encoding="utf-8")


def load_segments(job_dir: str | Path) -> list[dict]:
    """Read the segments saved in `job_dir`."""
    return json.loads((Path(job_dir) / "segments.json").read_text(encoding="utf-8"))


def save_segments(job_dir: str | Path, segments: list[dict]) -> None:
    """Write the segments to segments.json in `job_dir`."""
    (Path(job_dir) / "segments.json").write_text(json.dumps(segments, ensure_ascii=False, indent=1), encoding="utf-8")
