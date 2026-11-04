"""Stage 5: fit the Khmer clips to the speech timing, mix the voice track, merge it, and write the SRT."""

import logging
import subprocess
import wave
from pathlib import Path

import numpy as np
from pydub import AudioSegment

import config
from stages import StageError

log = logging.getLogger(__name__)

MAX_SPEED = 1.6  # clips are sped up at most this much, and never slowed down
TRACK_RATE = 24000  # the clips from Stage 4 are WAV 24 kHz mono
FADE_MS = 40


def build_track(segments: list[dict], total_ms: int, folder: str | Path) -> Path:
    """Place every clip at its segment's start on a silent track as long as the video; return folder/dub.wav.

    A clip may run into the silence after its slot. Only a clip longer than its room (up to the next
    segment or the end of the video) is sped up with FFmpeg atempo, at most MAX_SPEED. If it is still
    too long, its end is cut with a short fade. Each placed segment gets "speed" (factor used),
    "cut_ms" (speech cut off) and "dub_end" (when its voice ends, in seconds).
    """
    folder = Path(folder)
    # A NumPy buffer, because overlaying hundreds of clips on a long pydub track copies it every time.
    track = np.zeros(round(total_ms * TRACK_RATE / 1000), dtype=np.int16)
    total = total_ms / 1000

    for i, seg in enumerate(segments):
        if not seg.get("clip"):
            continue
        next_start = segments[i + 1]["start"] if i + 1 < len(segments) else total
        room_ms = int(min(next_start - seg["start"], total - seg["start"]) * 1000)
        if room_ms <= 0:
            log.warning("Segment %s has no room for its voice; skipped", seg["id"])
            continue

        clip = _load_clip(Path(seg["clip"]))
        speed = 1.0
        if len(clip) > room_ms:
            speed = min(len(clip) / room_ms, MAX_SPEED)
            clip = _speed_up(Path(seg["clip"]), speed)
        cut_ms = max(0, len(clip) - room_ms)
        if cut_ms:
            clip = clip[:room_ms].fade_out(min(FADE_MS, room_ms))

        start = round(seg["start"] * TRACK_RATE)
        samples = np.frombuffer(clip.raw_data, dtype=np.int16)[: len(track) - start]
        track[start:start + len(samples)] = samples
        seg.update(speed=round(speed, 2), cut_ms=cut_ms, dub_end=round(seg["start"] + len(samples) / TRACK_RATE, 3))

    out = folder / "dub.wav"
    folder.mkdir(parents=True, exist_ok=True)
    with wave.open(str(out), "wb") as wav:
        wav.setnchannels(1)
        wav.setsampwidth(2)
        wav.setframerate(TRACK_RATE)
        wav.writeframes(track.tobytes())
    return out


def merge(video: str | Path, dub_wav: str | Path, out: str | Path) -> Path:
    """Write an MP4 with the video's picture, and the Khmer track over the quiet original sound; return its path.

    The picture is copied as is (-c:v copy). Only when MP4 cannot hold the video codec (VP8 from
    WebM, for example) is it re-encoded to H.264. The original sound plays at ORIGINAL_VOLUME all the
    way through (0 leaves only the Khmer track). The sound is AAC, labelled as an AI-generated voice.
    """
    out = Path(out)
    inputs = ["-i", str(video), "-i", str(dub_wav), "-map", "0:v:0", *_sound_args(video)]
    audio = ["-c:a", "aac", "-b:a", "160k", "-metadata:s:a:0", "language=khm",
             "-metadata:s:a:0", "title=Khmer (AI-generated voice)",
             "-metadata", "comment=Dubbed into Khmer with an AI-generated voice", "-movflags", "+faststart", str(out)]
    try:
        _ffmpeg(*inputs, "-c:v", "copy", *audio)
    except subprocess.CalledProcessError as exc:
        reason = exc.stderr.strip().splitlines()[0] if exc.stderr.strip() else f"exit {exc.returncode}"
        log.warning("Cannot copy this video stream into MP4, re-encoding it to H.264: %s", reason)
        try:
            _ffmpeg(*inputs, "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", "-pix_fmt", "yuv420p",
                    "-vf", "scale=trunc(iw/2)*2:trunc(ih/2)*2", *audio)
        except subprocess.CalledProcessError as exc2:
            log.error("ffmpeg could not make the dubbed video: %s", exc2.stderr.strip())
            raise StageError("Could not make the dubbed video. Try converting the upload to MP4 first.") from exc2
    return out


def write_srt(segments: list[dict], path: str | Path) -> int:
    """Write the Khmer text of the segments as a UTF-8 SRT file at the original timestamps.

    Segments with empty `km` are left out and the cues are numbered from 1 without gaps.
    Returns the number of cues written.
    """
    path = Path(path)
    cues = [seg for seg in segments if seg.get("km", "").strip()]
    blocks = [
        f"{number}\n{_srt_time(seg['start'])} --> {_srt_time(seg['end'])}\n{seg['km'].strip()}\n"
        for number, seg in enumerate(cues, start=1)
    ]
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(blocks), encoding="utf-8")
    log.info("Wrote %d subtitles to %s", len(cues), path)
    return len(cues)


def _sound_args(video: str | Path) -> list[str]:
    """FFmpeg arguments that pick the sound: the Khmer track, mixed over the quieted original if there is one.

    The volume stays fixed instead of rising between lines, so the original speech never comes back
    loudly when a Khmer line ends early. The limiter stops the mix from clipping on loud moments.
    """
    if config.ORIGINAL_VOLUME <= 0 or not _has_sound(video):
        return ["-map", "1:a:0"]
    # rematrix_maxval=1 averages stereo into mono; without it the channels add up, 3 dB too loud.
    mix = (f"[0:a:0]aresample={TRACK_RATE}:ochl=mono:rematrix_maxval=1,volume={config.ORIGINAL_VOLUME}[original];"
           "[1:a:0][original]amix=inputs=2:duration=first:normalize=0,"
           "alimiter=limit=0.97:level=false:latency=true[sound]")
    return ["-filter_complex", mix, "-map", "[sound]"]


def _has_sound(video: str | Path) -> bool:
    """True if the file has an audio stream."""
    result = subprocess.run(["ffprobe", "-v", "error", "-select_streams", "a", "-show_entries", "stream=index",
                             "-of", "csv=p=0", str(video)], check=True, capture_output=True, text=True)
    return bool(result.stdout.strip())


def _load_clip(path: Path) -> AudioSegment:
    """Load a clip as 24 kHz mono 16-bit."""
    return AudioSegment.from_wav(path).set_frame_rate(TRACK_RATE).set_channels(1).set_sample_width(2)


def _speed_up(clip_path: Path, speed: float) -> AudioSegment:
    """Return the clip played `speed` times faster (pitch kept) with FFmpeg atempo."""
    fast = clip_path.with_name(f"{clip_path.stem}.x{speed:.2f}.wav")
    try:
        _ffmpeg("-i", str(clip_path), "-filter:a", f"atempo={speed:.4f}", "-ar", str(TRACK_RATE), "-ac", "1",
                "-c:a", "pcm_s16le", str(fast))
    except subprocess.CalledProcessError as exc:
        log.error("ffmpeg atempo failed on %s: %s", clip_path, exc.stderr.strip())
        raise StageError("Could not fit a Khmer voice clip to the speech timing.") from exc
    return _load_clip(fast)


def _ffmpeg(*args: str) -> None:
    """Run FFmpeg quietly; raises CalledProcessError with its stderr on failure."""
    subprocess.run(["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", *args],
                   check=True, capture_output=True, text=True)


def _srt_time(seconds: float) -> str:
    """Format seconds as an SRT timestamp, HH:MM:SS,mmm."""
    ms = round(seconds * 1000)
    return f"{ms // 3_600_000:02d}:{ms // 60_000 % 60:02d}:{ms // 1000 % 60:02d},{ms % 1000:03d}"
