"""Stage 2: speech to text with faster-whisper."""

import functools
import logging
import wave
from pathlib import Path

import numpy as np
from faster_whisper import WhisperModel

import config
from stages import StageError

log = logging.getLogger(__name__)


def transcribe(wav_path: str | Path) -> tuple[list[dict], str]:
    """Transcribe a 16 kHz WAV and return its timed segments and the detected language code (e.g. "en").

    Each segment is {"id", "start", "end", "text"}, with times in seconds from the start of the
    video. Silence is skipped with the VAD filter and empty segments are dropped. Raises StageError
    if no speech is found or the model cannot be loaded.
    """
    model = _load_model(config.WHISPER_MODEL, config.WHISPER_DEVICE)
    pieces, info = model.transcribe(_read_wav(Path(wav_path)), vad_filter=True)

    segments: list[dict] = []
    for piece in pieces:  # a generator: the decoding happens here
        text = piece.text.strip()
        if text:
            segments.append({"id": len(segments), "start": round(piece.start, 2), "end": round(piece.end, 2), "text": text})

    if not segments:
        raise StageError("No speech was found in the video.")
    log.info("Transcribed %d segments, language %s (p=%.2f)", len(segments), info.language, info.language_probability)
    return segments, info.language


def _read_wav(wav_path: Path) -> np.ndarray:
    """Read a mono 16 kHz 16-bit WAV (as made by extract_audio) into float32 samples in [-1, 1].

    We pass samples instead of a path because faster-whisper 1.2.1 opens files with an
    argument that PyAV 19 removed.
    """
    with wave.open(str(wav_path), "rb") as wav:
        if (wav.getnchannels(), wav.getframerate(), wav.getsampwidth()) != (1, 16000, 2):
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
