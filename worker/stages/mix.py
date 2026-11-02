"""Stage 5: timing, mixing, merging and the Khmer subtitle file.

Phase 3 builds only write_srt; build_track and merge are added in Phase 4.
"""

import logging
from pathlib import Path

log = logging.getLogger(__name__)


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


def _srt_time(seconds: float) -> str:
    """Format seconds as an SRT timestamp, HH:MM:SS,mmm."""
    ms = round(seconds * 1000)
    return f"{ms // 3_600_000:02d}:{ms // 60_000 % 60:02d}:{ms // 1000 % 60:02d},{ms % 1000:03d}"
