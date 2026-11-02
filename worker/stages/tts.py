"""Stage 4: Khmer voice clips. TTS_ENGINE picks edge-tts (online, natural) or MMS-TTS (offline, robotic)."""

import functools
import logging
import subprocess
import time
import wave
from pathlib import Path

import edge_tts
import numpy as np
import torch
from transformers import AutoTokenizer, VitsModel

import config
from stages import StageError

log = logging.getLogger(__name__)

VOICES = {"female": "km-KH-SreymomNeural", "male": "km-KH-PisethNeural"}
MMS_MODEL = "facebook/mms-tts-khm"
CLIP_RATE = 24000
EDGE_RETRIES = 2
EDGE_UNREACHABLE = (
    "The Khmer voice service (edge-tts) could not be reached. Check the internet connection, "
    "or set TTS_ENGINE=mms in worker/.env to use the offline voice."
)
# edge-tts puts about 0.2 s of silence before and 0.8 s after the speech. Cutting it makes the
# voice start on time and leaves more of the slot for the words.
_TRIM_START = "silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.05"
TRIM_SILENCE = f"{_TRIM_START},areverse,{_TRIM_START},areverse"


def synth(text: str, out_path: str | Path, gender: str) -> Path:
    """Speak Khmer `text` into `out_path` as a WAV 24 kHz mono clip, using the TTS_ENGINE setting.

    `gender` ("female" or "male") picks the edge-tts voice; MMS has a single voice. The silence
    around the speech is trimmed. Raises StageError for an unknown engine or voice, or when
    edge-tts is still unreachable after two retries.
    """
    out_path = Path(out_path)
    if gender not in VOICES:
        raise StageError(f"Unknown voice '{gender}'. Use female or male.")
    out_path.parent.mkdir(parents=True, exist_ok=True)

    if not any(ch.isalnum() for ch in text):  # only punctuation or spaces: nothing to say
        raw = _silence(out_path.with_suffix(".silent.wav"))
    elif config.TTS_ENGINE == "edge":
        raw = _edge(text, VOICES[gender], out_path.with_suffix(".edge.mp3"))
    elif config.TTS_ENGINE == "mms":
        raw = _mms(text, out_path.with_suffix(".mms.wav"))
    else:
        raise StageError(f"Unknown TTS_ENGINE '{config.TTS_ENGINE}' in worker/.env. Use edge or mms.")

    try:
        _to_clip(raw, out_path)
    finally:
        raw.unlink(missing_ok=True)
    return out_path


def synth_all(segments: list[dict], folder: str | Path, gender: str) -> list[dict]:
    """Write one clip per segment to `folder/<id>.wav` and set its `clip`. Skips segments with empty `km`."""
    folder = Path(folder)
    for seg in segments:
        if seg.get("km", "").strip():
            seg["clip"] = str(synth(seg["km"], folder / f"{seg['id']}.wav", gender))
    return segments


def _edge(text: str, voice: str, mp3: Path) -> Path:
    """Make the speech with edge-tts, retrying twice, and return the file written.

    Every failure is retried, including "no audio received", which the service sometimes sends
    for ordinary sentences that work on the next try.
    """
    error: Exception | None = None
    for attempt in range(EDGE_RETRIES + 1):
        try:
            edge_tts.Communicate(text, voice).save_sync(str(mp3))
            return mp3
        except Exception as exc:  # network errors, no audio, or the unofficial service changed
            error = exc
            log.warning("edge-tts attempt %d of %d failed: %s", attempt + 1, EDGE_RETRIES + 1, exc)
            if attempt < EDGE_RETRIES:
                time.sleep(2 * (attempt + 1))
    raise StageError(EDGE_UNREACHABLE) from error


def _mms(text: str, wav_path: Path) -> Path:
    """Make the speech locally with MMS-TTS and return the file written.

    Characters outside its Khmer alphabet (digits, Latin letters) are skipped.
    """
    tokenizer, model = _load_mms(MMS_MODEL)
    alphabet = tokenizer.get_vocab()
    if not any(ch in alphabet and not ch.isspace() for ch in text):  # e.g. only digits or Latin letters
        log.warning("MMS-TTS cannot read %r; using silence", text)
        return _silence(wav_path)
    with torch.inference_mode():
        samples = model(**tokenizer(text, return_tensors="pt")).waveform[0].numpy()
    return _write_wav(wav_path, samples, model.config.sampling_rate)


@functools.cache
def _load_mms(name: str) -> tuple[AutoTokenizer, VitsModel]:
    """Load the MMS-TTS tokenizer and model once per process; the first call downloads them."""
    try:
        return AutoTokenizer.from_pretrained(name), VitsModel.from_pretrained(name).eval()
    except Exception as exc:
        log.exception("Could not load MMS-TTS model %r", name)
        raise StageError(
            "Could not load the offline Khmer voice. The first run downloads about 145 MB, so check "
            "the internet connection, then try again."
        ) from exc


def _silence(path: Path) -> Path:
    """Write 0.1 s of silence, for text with nothing the engine can say."""
    return _write_wav(path, np.zeros(CLIP_RATE // 10), CLIP_RATE)


def _write_wav(path: Path, samples: np.ndarray, rate: int) -> Path:
    """Write float samples in [-1, 1] as a mono 16-bit WAV and return its path."""
    pcm = (np.clip(samples, -1.0, 1.0) * 32767).astype("<i2")
    with wave.open(str(path), "wb") as wav:
        wav.setnchannels(1)
        wav.setsampwidth(2)
        wav.setframerate(rate)
        wav.writeframes(pcm.tobytes())
    return path


def _to_clip(src: Path, out_path: Path) -> None:
    """Convert any audio file to WAV 24 kHz mono 16-bit with the silence around the speech cut."""
    try:
        subprocess.run(
            ["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", "-i", str(src),
             "-af", TRIM_SILENCE, "-ac", "1", "-ar", str(CLIP_RATE), "-c:a", "pcm_s16le", str(out_path)],
            check=True, capture_output=True, text=True,
        )
    except subprocess.CalledProcessError as exc:
        log.error("ffmpeg could not convert %s: %s", src, exc.stderr.strip())
        raise StageError("Could not prepare a Khmer voice clip.") from exc
