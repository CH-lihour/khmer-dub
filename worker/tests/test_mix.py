"""Stage 5: write_srt (build_track and merge are added in Phase 4)."""

from pathlib import Path

from stages.mix import write_srt


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
