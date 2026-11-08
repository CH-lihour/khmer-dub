"""Optional stage: take the speech out of the original sound with Demucs, keeping music and effects."""

import functools
import logging
import subprocess
from collections.abc import Callable
from pathlib import Path

import numpy as np
import torch

from stages import StageError

log = logging.getLogger(__name__)

MODEL_NAME = "htdemucs"  # Demucs v4 (MIT); about 80 MB, downloaded once into TORCH_HOME
OUT_RATE = 24000  # the rate of the Khmer track, so the mix needs no second resampling


def separate(video_path: str | Path, out_wav: str | Path, on_step: Callable[[int, int], None] | None = None) -> Path:
    """Write the video's sound without its speech (drums, bass and the rest) as a mono 24 kHz WAV.

    Takes about half the length of the video on CPU. `on_step(done, total)` reports how many
    seconds have been separated. Raises StageError if the model cannot be loaded or the sound
    cannot be read.
    """
    model = _load_model(MODEL_NAME)
    rate = model.samplerate
    try:
        raw = subprocess.run(["ffmpeg", "-nostdin", "-v", "error", "-i", str(video_path), "-vn",
                              "-f", "f32le", "-ac", "2", "-ar", str(rate), "-"], check=True, capture_output=True).stdout
    except subprocess.CalledProcessError as exc:
        log.error("ffmpeg could not read the sound for separation: %s", exc.stderr.decode(errors="replace").strip())
        raise StageError("Could not read the sound of this video. Try converting it to MP4 first.") from exc
    mix = torch.from_numpy(np.frombuffer(raw, dtype="<f4").reshape(-1, 2).T.copy())
    total = int(mix.shape[1] / rate)

    def report(state: dict) -> None:
        if on_step and state.get("state") == "end":
            on_step(min(total, int(state["segment_offset"] / rate)), total)

    # Demucs works best on normalised sound; the result is scaled back.
    mean, std = mix.mean(), mix.std() + 1e-8
    with torch.inference_mode():
        sources = _apply(model, (mix - mean) / std, report)[0] * std + mean
    background = sources[[i for i, name in enumerate(model.sources) if name != "vocals"]].sum(0).mean(0)
    if on_step:
        on_step(total, total)

    out_wav = Path(out_wav)
    out_wav.parent.mkdir(parents=True, exist_ok=True)
    pcm = background.clamp(-1, 1).numpy().astype("<f4").tobytes()
    subprocess.run(["ffmpeg", "-nostdin", "-v", "error", "-y", "-f", "f32le", "-ar", str(rate), "-ac", "1", "-i", "-",
                    "-ar", str(OUT_RATE), "-c:a", "pcm_s16le", str(out_wav)], input=pcm, check=True, capture_output=True)
    log.info("Separated the speech from %.0f s of sound", total)
    return out_wav


def _apply(model: torch.nn.Module, mix: torch.Tensor, report: Callable[[dict], None]) -> torch.Tensor:
    """Run the model over the whole sound in overlapping pieces (Demucs' own splitting)."""
    from demucs.apply import apply_model

    return apply_model(model, mix[None], split=True, overlap=0.25, progress=False, callback=report)


@functools.cache
def _load_model(name: str) -> torch.nn.Module:
    """Load the Demucs model once per process; the first call downloads it."""
    try:
        from demucs import pretrained

        model = pretrained.get_model(name)
    except Exception as exc:
        log.exception("Could not load Demucs model %r", name)
        raise StageError(
            "Could not load the model that removes the original speech. The first run downloads "
            "about 80 MB, so check the internet connection, then try again."
        ) from exc
    model.eval()
    return model
