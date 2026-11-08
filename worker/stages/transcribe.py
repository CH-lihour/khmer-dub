"""Stage 2: speech to text with faster-whisper."""

import functools
import logging
import re
import wave
from collections.abc import Callable
from pathlib import Path

import numpy as np
from faster_whisper import WhisperModel
from faster_whisper.transcribe import Segment
from faster_whisper.vad import VadOptions, get_speech_timestamps

import config
from stages import StageError

log = logging.getLogger(__name__)

SAMPLE_RATE = 16000
SNAP_SECONDS = 0.5  # how close to the edge of a stretch of speech a boundary must be to snap onto it
SPLIT_PAUSE_SECONDS = 1.0  # a pause this long between two words always starts a new segment
JOIN_GAP_SECONDS = 2.5  # two lines further apart than this are never joined into one sentence
JOIN_MAX_SECONDS = 12.0  # a joined line is never longer than this, so its subtitle stays readable
SENTENCE_END = re.compile(r"[.!?…。！？។៕][\"'”’)\]»]*$")
CLAUSE_MARKS = ",;:，、；：-–—"  # a line ending in one of these goes on in the next line


def transcribe(wav_path: str | Path, on_step: Callable[[int, int], None] | None = None,
               language: str | None = None) -> tuple[list[dict], str]:
    """Transcribe a 16 kHz WAV and return its timed segments and the language code (e.g. "en").

    `language` is the spoken language as a Whisper code when the user chose it; None detects it.

    Each segment is {"id", "start", "end", "text"}, with times in seconds from the start of the
    video. Silence is skipped with the VAD filter and empty segments are dropped. Whisper's
    boundaries are then moved onto the detected speech, because it often starts a line early, and
    the parts of a sentence that was split over two lines are joined, so it is translated whole.
    `on_step(done, total)` reports how many seconds of the video have been heard so far.
    Raises StageError if no speech is found or the model cannot be loaded.
    """
    audio = _read_wav(Path(wav_path))
    model = _load_model(config.WHISPER_MODEL, config.WHISPER_DEVICE)
    # Word timestamps make Whisper fit each segment's edges to its first and last word.
    pieces, info = model.transcribe(audio, vad_filter=True, word_timestamps=True, language=language or None)

    segments: list[dict] = []
    total = int(info.duration)
    for piece in pieces:  # a generator: the decoding happens here
        for start, end, text in _split_at_pauses(piece):
            if text.strip():
                segments.append({"id": len(segments), "start": start, "end": end, "text": text.strip()})
        if on_step:
            on_step(min(int(piece.end), total), total)

    if not segments:
        raise StageError("No speech was found in the video.")
    _snap_to_speech(segments, _speech_regions(audio))
    for seg in segments:
        seg["start"], seg["end"] = round(seg["start"], 2), round(seg["end"], 2)
    segments = _join_fragments(segments)
    log.info("Transcribed %d segments, language %s (p=%.2f)", len(segments), info.language, info.language_probability)
    return segments, info.language


def _join_fragments(segments: list[dict]) -> list[dict]:
    """Return the segments with each sentence fragment joined to the line before it, renumbered.

    Translating a line that stops mid-sentence loses the context of the rest. A joined line keeps
    the first start and the last end, so it gets one subtitle and one voice clip.
    """
    joined: list[dict] = []
    for seg in segments:
        if joined and _continues(joined[-1], seg):
            joined[-1].update(end=seg["end"], text=f"{joined[-1]['text']} {seg['text']}")
        else:
            joined.append({**seg, "id": len(joined)})
    return joined


def _continues(line: dict, following: dict) -> bool:
    """True if `following` clearly goes on with the sentence of `line`, and joining them stays short.

    Many lines have no end punctuation at all, so a missing full stop proves nothing: the line must
    end in a clause mark such as a comma, or the next one must start with a lowercase letter.
    Scripts without capitals (Chinese, Thai, Khmer) therefore only join on a clause mark.
    """
    if following["start"] - line["end"] > JOIN_GAP_SECONDS or following["end"] - line["start"] > JOIN_MAX_SECONDS:
        return False
    if SENTENCE_END.search(line["text"]):
        return False
    return line["text"][-1] in CLAUSE_MARKS or following["text"][0].islower()


def _split_at_pauses(piece: Segment) -> list[tuple[float, float, str]]:
    """Split a Whisper segment into (start, end, text) parts wherever two words are far apart.

    The VAD filter cuts silences out before Whisper hears the audio, so Whisper can run two
    sentences with a long pause between them into one segment.
    """
    words = piece.words or []
    if not words:
        return [(piece.start, piece.end, piece.text)]
    parts = [[words[0]]]
    for prev, word in zip(words, words[1:]):
        if word.start - prev.end >= SPLIT_PAUSE_SECONDS:
            parts.append([])
        parts[-1].append(word)
    if len(parts) == 1:
        return [(piece.start, piece.end, piece.text)]
    return [(part[0].start, part[-1].end, "".join(word.word for word in part)) for part in parts]


def _speech_regions(audio: np.ndarray) -> list[tuple[float, float]]:
    """Return the (start, end) seconds of each stretch of speech, split at pauses of 0.3 s or more."""
    options = VadOptions(min_silence_duration_ms=300, speech_pad_ms=0)
    return [(ts["start"] / SAMPLE_RATE, ts["end"] / SAMPLE_RATE) for ts in get_speech_timestamps(audio, options)]


def _snap_to_speech(segments: list[dict], regions: list[tuple[float, float]]) -> None:
    """Move segment edges onto the speech they belong to, in place.

    A start that falls in silence, or in the last moments of the previous line's speech, moves
    forward to the next speech onset. An end that falls in silence moves back to the last speech
    offset; an end just short of an offset moves out to it, but never past the next segment's start.
    Edges only move inside the segment's own time span, plus at most SNAP_SECONDS at the end.
    """
    def region_at(t: float) -> tuple[float, float] | None:
        return next((r for r in regions if r[0] <= t <= r[1]), None)

    prev_end = 0.0
    for seg in segments:
        original_end, inside = seg["end"], region_at(seg["start"])
        in_previous_tail = inside is not None and inside[1] - seg["start"] < SNAP_SECONDS and inside[0] < prev_end
        if inside is None or in_previous_tail:
            onset = next((r[0] for r in regions if r[0] > seg["start"]), None)
            if onset is not None and onset < seg["end"]:
                seg["start"] = onset
        prev_end = original_end

    for i, seg in enumerate(segments):
        next_start = segments[i + 1]["start"] if i + 1 < len(segments) else float("inf")
        inside = region_at(seg["end"])
        if inside is None:
            offset = max((r[1] for r in regions if seg["start"] < r[1] < seg["end"]), default=None)
            if offset is not None:
                seg["end"] = offset
        elif inside[1] - seg["end"] < SNAP_SECONDS:
            seg["end"] = max(seg["end"], min(inside[1], next_start))


def _read_wav(wav_path: Path) -> np.ndarray:
    """Read a mono 16 kHz 16-bit WAV (as made by extract_audio) into float32 samples in [-1, 1].

    We pass samples instead of a path because faster-whisper 1.2.1 opens files with an
    argument that PyAV 19 removed.
    """
    with wave.open(str(wav_path), "rb") as wav:
        if (wav.getnchannels(), wav.getframerate(), wav.getsampwidth()) != (1, SAMPLE_RATE, 2):
            raise ValueError(f"{wav_path} is not mono 16 kHz 16-bit PCM; make it with extract_audio")
        frames = wav.readframes(wav.getnframes())
    return np.frombuffer(frames, dtype="<i2").astype(np.float32) / 32768.0


@functools.cache
def _load_model(name: str, device: str) -> WhisperModel:
    """Load the Whisper model once per process; the first call downloads it into HF_HOME."""
    compute_type = "float16" if device == "cuda" else "int8"
    try:
        return WhisperModel(name, device=device, compute_type=compute_type)
    except Exception as exc:
        log.exception("Could not load Whisper model %r on %s", name, device)
        raise StageError(
            f"Could not load the speech model '{name}'. The first run downloads it, so check the "
            "internet connection and the WHISPER_MODEL setting, then try again."
        ) from exc
