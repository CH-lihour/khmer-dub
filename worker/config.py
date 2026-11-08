"""Worker settings, read from environment variables (worker/.env, loaded by Compose).

The stages and app.py read these as `config.NAME` at call time, so tests can override them with monkeypatch.
"""

import os
from pathlib import Path

WORKER_TOKEN: str = os.environ.get("WORKER_TOKEN", "").strip()  # empty refuses every API call
WHISPER_MODEL: str = os.environ.get("WHISPER_MODEL", "small")
WHISPER_DEVICE: str = os.environ.get("WHISPER_DEVICE", "cpu")
TTS_ENGINE: str = os.environ.get("TTS_ENGINE", "edge")
WORK_DIR: Path = Path(os.environ.get("WORK_DIR", "/data/jobs"))
MAX_VIDEO_MINUTES: float = float(os.environ.get("MAX_VIDEO_MINUTES", "15"))
ORIGINAL_VOLUME: float = float(os.environ.get("ORIGINAL_VOLUME", "0.15"))  # default for jobs sent without original_volume; 0 = none
KHMER_SPACES: str = os.environ.get("KHMER_SPACES", "keep").strip().lower()  # keep | remove: NLLB's spaces between Khmer words
