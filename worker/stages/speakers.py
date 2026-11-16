"""Stage 2b: who speaks each line, and whether each speaker sounds like a man or a woman.

Each line gets a voice print from WavLM-Base-Plus-SV (Microsoft, CC BY-SA 3.0), run locally with
`transformers`. Lines whose prints are alike are grouped into one speaker. A speaker's typical
pitch then picks the female or the male Khmer voice. Both are guesses a person can correct in review.
"""

import logging
import wave
from collections.abc import Callable
from pathlib import Path

import numpy as np
import torch
from transformers import AutoFeatureExtractor, WavLMForXVector

import config
from stages import StageError

log = logging.getLogger(__name__)

SPEAKER_MODEL = "microsoft/wavlm-base-plus-sv"
SAMPLE_RATE = 16000
MIN_PRINT_SECONDS = 1.0  # a shorter line says too little about its voice; it joins the speaker next to it
MAX_PRINT_SECONDS = 10.0  # enough for a voice print; more only costs time
MIN_SPEAKER_SECONDS = 3.0  # a group with less speech than this is probably a stray line, not a person
MALE_BELOW_HZ = 165.0  # typical pitch: men about 85-155 Hz, women about 165-255 Hz
PITCH_FLOOR_HZ, PITCH_CEILING_HZ = 70.0, 400.0


def find_speakers(wav_path: str | Path, segments: list[dict],
                  on_step: Callable[[int, int], None] | None = None) -> list[dict]:
    """Set `speaker` (0, 1, ... in order of first appearance) on every segment and return the speakers.

    `wav_path` is the mono 16 kHz WAV of the video. Each speaker is {"id", "gender", "lines"}, with
    `gender` the guess from its pitch ("female" or "male"). Lines are grouped while their average
    voice-print similarity is at least SPEAKER_THRESHOLD, into at most MAX_SPEAKERS speakers.
    `on_step(done, total)` reports the lines heard so far. Raises StageError if the model cannot load.
    """
    if not segments:
        return []
    audio = _read_wav(Path(wav_path))
    long_lines = [i for i, seg in enumerate(segments) if seg["end"] - seg["start"] >= MIN_PRINT_SECONDS]
    if len(long_lines) < 2:  # nothing to compare: one speaker
        labels = [0] * len(segments)
    else:
        prints = _voice_prints(audio, [segments[i] for i in long_lines], on_step)
        groups = cluster(prints, config.SPEAKER_THRESHOLD, config.MAX_SPEAKERS)
        groups = _drop_small(groups, prints, [segments[i]["end"] - segments[i]["start"] for i in long_lines])
        labels = _fill_short_lines(segments, dict(zip(long_lines, groups)))

    order = {label: n for n, label in enumerate(dict.fromkeys(labels))}  # first to speak is speaker 0
    for seg, label in zip(segments, labels):
        seg["speaker"] = order[label]
    speakers = []
    for speaker in range(len(order)):
        own = [seg for seg in segments if seg["speaker"] == speaker]
        pitch = typical_pitch(np.concatenate([_cut(audio, seg) for seg in own]))
        gender = "male" if pitch is not None and pitch < MALE_BELOW_HZ else "female"
        speakers.append({"id": speaker, "gender": gender, "lines": len(own)})
        log.info("Speaker %d: %d lines, pitch %s Hz, %s", speaker, len(own), f"{pitch:.0f}" if pitch else "?", gender)
    return speakers


def cluster(prints: np.ndarray, threshold: float, max_groups: int) -> list[int]:
    """Group unit-length voice prints by average-linkage clustering on cosine similarity.

    Merges the two most alike groups while their average similarity is at least `threshold`, and
    then on until there are at most `max_groups`. Returns a group number for each print.
    """
    groups = [[i] for i in range(len(prints))]
    sims = prints @ prints.T
    while len(groups) > 1:
        best, pair = -2.0, (0, 1)
        for a in range(len(groups)):
            for b in range(a + 1, len(groups)):
                score = float(sims[np.ix_(groups[a], groups[b])].mean())
                if score > best:
                    best, pair = score, (a, b)
        if best < threshold and len(groups) <= max_groups:
            break
        a, b = pair
        groups[a] += groups.pop(b)
    labels = [0] * len(prints)
    for number, members in enumerate(groups):
        for i in members:
            labels[i] = number
    return labels


def typical_pitch(samples: np.ndarray) -> float | None:
    """The median pitch in Hz of the voiced 40 ms frames of 16 kHz `samples`, or None if none are voiced.

    A plain autocorrelation pitch finder: good enough to tell a low voice from a high one.
    """
    frame, hop = int(0.04 * SAMPLE_RATE), int(0.02 * SAMPLE_RATE)
    low, high = int(SAMPLE_RATE / PITCH_CEILING_HZ), int(SAMPLE_RATE / PITCH_FLOOR_HZ)
    loudness = np.sqrt(np.mean(samples ** 2)) if samples.size else 0.0
    pitches = []
    for start in range(0, len(samples) - frame, hop):
        chunk = samples[start:start + frame]
        chunk = chunk - chunk.mean()
        if np.sqrt(np.mean(chunk ** 2)) < 0.5 * loudness:  # quiet: a pause or a soft consonant
            continue
        corr = np.correlate(chunk, chunk, mode="full")[frame - 1:]
        if corr[0] <= 0:
            continue
        lag = low + int(np.argmax(corr[low:high]))
        if corr[lag] / corr[0] > 0.5:  # clearly periodic: a voiced sound
            pitches.append(SAMPLE_RATE / lag)
    return float(np.median(pitches)) if pitches else None


def _voice_prints(audio: np.ndarray, segments: list[dict], on_step: Callable[[int, int], None] | None) -> np.ndarray:
    """One unit-length WavLM voice print per segment."""
    extractor, model = _load_model(SPEAKER_MODEL)
    prints = []
    with torch.inference_mode():
        for done, seg in enumerate(segments, start=1):
            clip = _cut(audio, seg)[: int(MAX_PRINT_SECONDS * SAMPLE_RATE)]
            inputs = extractor(clip, sampling_rate=SAMPLE_RATE, return_tensors="pt")
            vector = model(**inputs).embeddings[0].numpy()
            prints.append(vector / (np.linalg.norm(vector) or 1.0))
            if on_step:
                on_step(done, len(segments))
    return np.stack(prints)


def _drop_small(groups: list[int], prints: np.ndarray, seconds: list[float]) -> list[int]:
    """Move the lines of groups with less than MIN_SPEAKER_SECONDS of speech to the most alike bigger group."""
    totals: dict[int, float] = {}
    for group, length in zip(groups, seconds):
        totals[group] = totals.get(group, 0.0) + length
    big = [group for group, total in totals.items() if total >= MIN_SPEAKER_SECONDS]
    if not big or len(big) == len(totals):
        return groups
    centres = {group: prints[[i for i, g in enumerate(groups) if g == group]].mean(axis=0) for group in big}
    return [group if group in centres else max(big, key=lambda b: float(prints[i] @ centres[b]))
            for i, group in enumerate(groups)]


def _fill_short_lines(segments: list[dict], known: dict[int, int]) -> list[int]:
    """Give each line too short for a voice print the speaker of the nearest line in time that has one."""
    def gap(i: int, j: int) -> float:
        a, b = segments[i], segments[j]
        return max(0.0, b["start"] - a["end"], a["start"] - b["end"])

    return [known[i] if i in known else known[min(known, key=lambda j: gap(i, j))] for i in range(len(segments))]


def _cut(audio: np.ndarray, seg: dict) -> np.ndarray:
    """The samples of one segment."""
    return audio[int(seg["start"] * SAMPLE_RATE): int(seg["end"] * SAMPLE_RATE)]


def _read_wav(wav_path: Path) -> np.ndarray:
    """Read a mono 16 kHz 16-bit WAV (as made by extract_audio) into float32 samples in [-1, 1]."""
    with wave.open(str(wav_path), "rb") as wav:
        if (wav.getnchannels(), wav.getframerate(), wav.getsampwidth()) != (1, SAMPLE_RATE, 2):
            raise ValueError(f"{wav_path} is not mono 16 kHz 16-bit PCM; make it with extract_audio")
        frames = wav.readframes(wav.getnframes())
    return np.frombuffer(frames, dtype="<i2").astype(np.float32) / 32768.0


def _load_model(name: str) -> tuple[AutoFeatureExtractor, WavLMForXVector]:
    """Load the voice-print model; the first call downloads about 400 MB into HF_HOME.

    Not kept between jobs: it is only needed for a few seconds, and the other models need the memory.
    """
    try:
        return AutoFeatureExtractor.from_pretrained(name), WavLMForXVector.from_pretrained(name).eval()
    except Exception as exc:
        log.exception("Could not load speaker model %r", name)
        raise StageError(
            "Could not load the speaker model. The first run downloads about 400 MB, so check the "
            "internet connection, then try again."
        ) from exc
