"""Stage 5: build_track, merge and write_srt."""

import wave
from pathlib import Path

import numpy as np
import pytest

from stages.mix import build_track, merge, write_srt
from tests.conftest import ffmpeg, probe, tone


def read_track(path: Path) -> np.ndarray:
    """Read a mono 24 kHz 16-bit WAV."""
    with wave.open(str(path), "rb") as wav:
        assert (wav.getnchannels(), wav.getframerate(), wav.getsampwidth()) == (1, 24000, 2)
        return np.frombuffer(wav.readframes(wav.getnframes()), dtype="<i2")


def loudness(track: np.ndarray, start: float, end: float) -> float:
    """Root mean square of the track between two times in seconds."""
    part = track[int(start * 24000):int(end * 24000)].astype(np.float64)
    return float(np.sqrt(np.mean(part ** 2)))


def test_build_track_timing_rules(tmp_path: Path) -> None:
    clips = tmp_path / "clips"
    clips.mkdir()
    segments = [
        {"id": 0, "start": 1.0, "end": 2.0, "clip": tone(clips / "0.wav", 0.5)},  # short: never slowed down
        {"id": 1, "start": 3.0, "end": 4.0, "clip": tone(clips / "1.wav", 1.2)},  # within the 25 % overrun
        {"id": 2, "start": 5.0, "end": 6.0, "clip": tone(clips / "2.wav", 1.8)},  # needs x1.44 to fit 1.25 s
        {"id": 3, "start": 6.5, "end": 7.0, "clip": tone(clips / "3.wav", 2.0)},  # too long even at x1.6
        {"id": 4, "start": 7.4, "end": 9.0, "km": ""},  # nothing to say
    ]
    track = read_track(build_track(segments, 10_000, tmp_path))

    assert len(track) == 10 * 24000
    assert [seg["speed"] for seg in segments[:4]] == [1.0, 1.0, 1.44, 1.6]
    assert [seg["dub_end"] for seg in segments[:4]] == pytest.approx([1.5, 4.2, 6.25, 7.125], abs=0.03)
    assert segments[3]["cut_ms"] == pytest.approx(625, abs=30)  # 2.0 s / 1.6 = 1.25 s, room 0.625 s
    assert segments[0]["cut_ms"] == segments[1]["cut_ms"] == 0
    assert "speed" not in segments[4]
    for seg, after in zip(segments, segments[1:]):
        assert seg.get("dub_end", 0) <= after["start"], f"segment {seg['id']} runs into the next one"

    for start, end in [(0, 0.95), (1.55, 2.95), (4.25, 4.95), (6.3, 6.45), (7.15, 10)]:
        assert loudness(track, start, end) < 1, f"expected silence {start}-{end} s"
    for start, end in [(1.05, 1.45), (3.05, 4.15), (5.05, 6.2), (6.55, 7.1)]:
        assert loudness(track, start, end) > 1000, f"expected voice {start}-{end} s"


def test_build_track_stops_at_the_end_of_the_video(tmp_path: Path) -> None:
    segments = [{"id": 0, "start": 9.5, "end": 10.5, "clip": tone(tmp_path / "0.wav", 1.0)}]
    track = read_track(build_track(segments, 10_000, tmp_path))
    assert len(track) == 10 * 24000
    assert segments[0]["dub_end"] <= 10.0


def test_merge_copies_the_picture(tone_video: Path, tmp_path: Path) -> None:
    out = merge(tone_video, tone(tmp_path / "dub.wav", 2.0), tmp_path / "dubbed.mp4")

    assert probe(out, "-show_entries", "stream=codec_type,codec_name") == ["mpeg4,video", "aac,audio"]
    assert probe(out, "-select_streams", "a", "-show_entries", "stream_tags=language") == ["khm"]
    same_packets = ["-select_streams", "v", "-show_entries", "packet=data_hash", "-show_data_hash", "MD5"]
    assert probe(out, *same_packets) == probe(tone_video, *same_packets), "the video was re-encoded"


def test_merge_reencodes_what_mp4_cannot_hold(tmp_path: Path) -> None:
    webm = tmp_path / "vp8.webm"
    ffmpeg("-f", "lavfi", "-i", "testsrc=size=160x120:rate=10", "-t", "1", "-c:v", "libvpx", "-an", str(webm))
    out = merge(webm, tone(tmp_path / "dub.wav", 1.0), tmp_path / "dubbed.mp4")
    assert probe(out, "-show_entries", "stream=codec_type,codec_name") == ["h264,video", "aac,audio"]


def test_write_srt(tmp_path: Path) -> None:
    segments = [
        {"id": 0, "start": 0.61, "end": 5.18, "text": "We have", "km": "យើង មាន"},
        {"id": 1, "start": 6.0, "end": 7.0, "text": "uh", "km": "  "},  # nothing to show
        {"id": 2, "start": 8.0, "end": 9.0, "text": "Untranslated"},
        {"id": 3, "start": 3723.456, "end": 3725.0, "text": "Thank you.", "km": " អរគុណ។ "},
    ]
    path = tmp_path / "new" / "khmer.srt"

    assert write_srt(segments, path) == 2
    # Plain UTF-8 (no BOM), numbered from 1 without gaps, original times, trimmed Khmer text.
    assert path.read_bytes().decode("utf-8") == (
        "1\n00:00:00,610 --> 00:00:05,180\nយើង មាន\n\n"
        "2\n01:02:03,456 --> 01:02:05,000\nអរគុណ។\n"
    )


def test_write_srt_with_no_text_writes_an_empty_file(tmp_path: Path) -> None:
    path = tmp_path / "khmer.srt"
    assert write_srt([{"id": 0, "start": 0.0, "end": 1.0, "text": "x", "km": ""}], path) == 0
    assert path.read_text(encoding="utf-8") == ""
