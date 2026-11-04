"""Stage 5: build_track, merge and write_srt."""

import wave
from pathlib import Path

import numpy as np
import pytest

import config
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
        {"id": 1, "start": 3.0, "end": 4.0, "clip": tone(clips / "1.wav", 1.9)},  # uses the silence after its slot
        {"id": 2, "start": 5.0, "end": 6.0, "clip": tone(clips / "2.wav", 1.8)},  # needs x1.2 to fit 1.5 s
        {"id": 3, "start": 6.5, "end": 7.0, "clip": tone(clips / "3.wav", 2.0)},  # too long even at x1.6
        {"id": 4, "start": 7.4, "end": 9.0, "km": ""},  # nothing to say
    ]
    track = read_track(build_track(segments, 10_000, tmp_path))

    assert len(track) == 10 * 24000
    assert [seg["speed"] for seg in segments[:4]] == [1.0, 1.0, 1.2, 1.6]
    assert [seg["dub_end"] for seg in segments[:4]] == pytest.approx([1.5, 4.9, 6.5, 7.4], abs=0.03)
    assert segments[3]["cut_ms"] == pytest.approx(350, abs=30)  # 2.0 s / 1.6 = 1.25 s, room 0.9 s
    assert segments[0]["cut_ms"] == segments[1]["cut_ms"] == 0
    assert "speed" not in segments[4]
    for seg, after in zip(segments, segments[1:]):
        assert seg.get("dub_end", 0) <= after["start"], f"segment {seg['id']} runs into the next one"

    for start, end in [(0, 0.95), (1.55, 2.95), (4.93, 4.99), (7.45, 10)]:
        assert loudness(track, start, end) < 1, f"expected silence {start}-{end} s"
    for start, end in [(1.05, 1.45), (3.05, 4.85), (5.05, 6.45), (6.55, 7.35)]:
        assert loudness(track, start, end) > 1000, f"expected voice {start}-{end} s"


def test_build_track_saves_a_very_short_slot_with_the_silence_after_it(tmp_path: Path) -> None:
    """Line 4 of the test clip: a 0.5 s slot, 2.4 s of Khmer, and 2.8 s until the next line."""
    segments = [
        {"id": 0, "start": 1.0, "end": 1.5, "clip": tone(tmp_path / "0.wav", 2.4)},
        {"id": 1, "start": 3.8, "end": 5.0, "clip": tone(tmp_path / "1.wav", 0.5)},
    ]
    build_track(segments, 6_000, tmp_path)

    assert (segments[0]["speed"], segments[0]["cut_ms"]) == (1.0, 0)
    assert segments[0]["dub_end"] == pytest.approx(3.4, abs=0.03)


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


def merged_sound(mp4: Path, tmp_path: Path) -> np.ndarray:
    """Decode the sound of an MP4 to mono 24 kHz samples."""
    wav = tmp_path / "decoded.wav"
    ffmpeg("-i", str(mp4), "-ac", "1", "-ar", "24000", "-c:a", "pcm_s16le", str(wav))
    return read_track(wav)


def silence(path: Path, seconds: float) -> str:
    """Write a silent WAV 24 kHz mono track and return its path as a string."""
    ffmpeg("-f", "lavfi", "-i", "anullsrc=r=24000:cl=mono", "-t", str(seconds), "-c:a", "pcm_s16le", str(path))
    return str(path)


def test_merge_keeps_the_original_sound_quietly(tone_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    original = merged_sound(tone_video, tmp_path)

    monkeypatch.setattr(config, "ORIGINAL_VOLUME", 0.15)
    out = merge(tone_video, silence(tmp_path / "dub.wav", 2.0), tmp_path / "dubbed.mp4")

    sound = merged_sound(out, tmp_path)
    ratio = loudness(sound, 0.5, 1.5) / loudness(original, 0.5, 1.5)
    assert ratio == pytest.approx(0.15, abs=0.02)


def test_merge_keeps_the_voice_at_full_level_over_the_original(
    tone_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    dub = tone(tmp_path / "dub.wav", 2.0, frequency=1000)
    monkeypatch.setattr(config, "ORIGINAL_VOLUME", 0)
    alone = merged_sound(merge(tone_video, dub, tmp_path / "alone.mp4"), tmp_path)

    monkeypatch.setattr(config, "ORIGINAL_VOLUME", 0.15)
    mixed = merged_sound(merge(tone_video, dub, tmp_path / "mixed.mp4"), tmp_path)

    assert loudness(mixed, 0.5, 1.5) >= loudness(alone, 0.5, 1.5) * 0.95


def test_merge_keeps_the_voice_in_time_with_the_track(tone_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    """A voice that starts at 1 s stays there: the limiter must not delay the sound."""
    clip = tone(tmp_path / "clip.wav", 0.5, frequency=1000)
    dub = tmp_path / "with-voice.wav"
    ffmpeg("-i", clip, "-af", "adelay=1000:all=1,apad=whole_dur=2", "-ar", "24000", "-ac", "1", "-c:a", "pcm_s16le", str(dub))
    monkeypatch.setattr(config, "ORIGINAL_VOLUME", 0.15)

    sound = merged_sound(merge(tone_video, dub, tmp_path / "dubbed.mp4"), tmp_path)

    assert loudness(sound, 1.1, 1.4) > 3 * loudness(sound, 0.5, 0.9), "no voice at 1.1-1.4 s"
    assert loudness(sound, 1.6, 1.9) * 3 < loudness(sound, 1.1, 1.4), "voice still playing after 1.5 s"


def test_merge_without_the_original_sound(tone_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "ORIGINAL_VOLUME", 0)
    out = merge(tone_video, silence(tmp_path / "dub.wav", 2.0), tmp_path / "dubbed.mp4")
    assert loudness(merged_sound(out, tmp_path), 0.5, 1.5) < 1


def test_merge_of_a_video_without_sound_uses_only_the_voice(silent_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(config, "ORIGINAL_VOLUME", 0.15)
    out = merge(silent_video, tone(tmp_path / "dub.wav", 1.0), tmp_path / "dubbed.mp4")
    assert probe(out, "-show_entries", "stream=codec_type") == ["video", "audio"]


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
