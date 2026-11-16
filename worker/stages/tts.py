"""Stage 4: Khmer voice clips. TTS_ENGINE picks edge-tts (online, natural) or MMS-TTS (offline, robotic)."""

import functools
import hashlib
import logging
import subprocess
import time
import wave
from collections.abc import Callable
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
EDGE_RETRIES = 2  # after a network error
# At busy times the service answers "no audio" for 7 to 37% of ordinary lines at random (measured
# 2026-11), and the same line works a second later. With 3 tries a 200-line video lost about 9 lines
# a run; 10 tries make that about one video in 200. The worst case, about 40 s, fits the review
# page's 60 s wait for one line.
EDGE_NO_AUDIO_TRIES = 10
EDGE_NO_AUDIO_WAIT = 1.0
EDGE_UNREACHABLE = (
    "The Khmer voice service (edge-tts) could not be reached. Check the internet connection, "
    "or set TTS_ENGINE=mms in worker/.env to use the offline voice."
)
# edge-tts puts about 0.2 s of silence before and 0.8 s after the speech. Cutting it makes the
# voice start on time and leaves more of the slot for the words.
_TRIM_START = "silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.05"
TRIM_SILENCE = f"{_TRIM_START},areverse,{_TRIM_START},areverse"


def synth(text: str, out_path: str | Path, gender: str, pitch: int = 0) -> Path:
    """Speak Khmer `text` into `out_path` as a WAV 24 kHz mono clip, using the TTS_ENGINE setting.

    `gender` ("female" or "male") picks the edge-tts voice; MMS has a single voice. `pitch` moves
    the voice up or down that many semitones, keeping its speed, so two speakers with the same
    voice still sound apart. The silence around the speech is trimmed. Raises StageError for an
    unknown engine or voice, or when edge-tts is still unreachable after two retries.
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
        _to_clip(raw, out_path, pitch)
    finally:
        raw.unlink(missing_ok=True)
    return out_path


def synth_all(segments: list[dict], folder: str | Path, gender: str,
              on_step: Callable[[int, int], None] | None = None,
              voices: dict[int, tuple[str, int]] | None = None) -> list[dict]:
    """Make one clip per segment in `folder` and set its `clip`. Skips segments with empty `km`.

    `voices` maps a segment's `speaker` to its (gender, pitch); a segment without a known speaker
    gets `gender` at its normal pitch. A clip already in `folder` for the same text and voice is
    used again (see cached_clip), so making a job again only voices the lines that changed.
    `on_step(done, total)` reports the lines voiced so far.
    """
    voices = voices or {}
    todo = [seg for seg in segments if seg.get("km", "").strip()]
    made = 0
    for done, seg in enumerate(todo, start=1):
        voice, pitch = voices.get(seg.get("speaker"), (gender, 0))
        clip, new = cached_clip(seg["km"], folder, voice, pitch)
        seg["clip"], made = str(clip), made + new
        if on_step:
            on_step(done, len(todo))
    log.info("Voiced %d lines, %d of them again from earlier clips", len(todo), len(todo) - made)
    return segments


def clip_path(text: str, folder: str | Path, gender: str, pitch: int = 0) -> Path:
    """Where the clip of `text` in this voice is kept in `folder`: named by the engine, voice, pitch and text.

    MMS has one voice, so with it the gender does not change the clip.
    """
    voice = VOICES.get(gender, gender) if config.TTS_ENGINE == "edge" else config.TTS_ENGINE
    key = "\n".join([config.TTS_ENGINE, voice, str(pitch), text.strip()])
    return Path(folder) / f"{hashlib.sha1(key.encode()).hexdigest()[:20]}.wav"


def cached_clip(text: str, folder: str | Path, gender: str, pitch: int = 0) -> tuple[Path, bool]:
    """Return the clip of `text` in this voice, made now unless `folder` has it already, and whether it is new.

    The clip is written under another name first, so a failure never leaves half a clip to be used again.
    """
    path = clip_path(text, folder, gender, pitch)
    if path.is_file():
        return path, False
    partial = path.with_name(f"{path.stem}.partial.wav")
    try:
        synth(text.strip(), partial, gender, pitch)
        partial.replace(path)
    finally:
        partial.unlink(missing_ok=True)
    return path, True


def _edge(text: str, voice: str, mp3: Path) -> Path:
    """Make the speech with edge-tts and return the file written.

    A network error is retried twice. "No audio received", which the service sends at random for
    ordinary lines that work on the next try, is tried up to EDGE_NO_AUDIO_TRIES times. If it still
    sends no audio, the service was reached but did not read the line, which is a different problem
    from being unreachable.
    """
    errors = no_audio = 0
    while True:
        try:
            edge_tts.Communicate(text, voice).save_sync(str(mp3))
            return mp3
        except edge_tts.exceptions.NoAudioReceived as exc:
            no_audio += 1
            log.warning("edge-tts sent no audio (try %d of %d): %s", no_audio, EDGE_NO_AUDIO_TRIES, text[:60])
            if no_audio >= EDGE_NO_AUDIO_TRIES:
                raise StageError(f"The Khmer voice could not read this line: {text[:60]}") from exc
            time.sleep(EDGE_NO_AUDIO_WAIT)
        except Exception as exc:  # network errors, or the unofficial service changed
            errors += 1
            log.warning("edge-tts attempt %d of %d failed: %s", errors, EDGE_RETRIES + 1, exc)
            if errors > EDGE_RETRIES:
                raise StageError(EDGE_UNREACHABLE) from exc
            time.sleep(2 * errors)


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


def _to_clip(src: Path, out_path: Path, pitch: int = 0) -> None:
    """Convert any audio file to WAV 24 kHz mono 16-bit with the silence around the speech cut.

    A `pitch` other than 0 moves the voice that many semitones: played faster at a higher rate,
    then slowed back to its own speed.
    """
    filters = TRIM_SILENCE
    if pitch:
        factor = 2 ** (pitch / 12)
        filters = (f"aresample={CLIP_RATE},asetrate={round(CLIP_RATE * factor)},aresample={CLIP_RATE},"
                   f"atempo={1 / factor:.5f},{TRIM_SILENCE}")
    try:
        subprocess.run(
            ["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", "-i", str(src),
             "-af", filters, "-ac", "1", "-ar", str(CLIP_RATE), "-c:a", "pcm_s16le", str(out_path)],
            check=True, capture_output=True, text=True,
        )
    except subprocess.CalledProcessError as exc:
        log.error("ffmpeg could not convert %s: %s", src, exc.stderr.strip())
        raise StageError("Could not prepare a Khmer voice clip.") from exc
