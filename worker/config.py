"""Worker settings, read from environment variables (worker/.env, loaded by Compose).

Stages read these as `config.NAME` at call time, so tests can override them with monkeypatch.
"""

import os
from pathlib import Path

WORKER_TOKEN: str = os.environ.get("WORKER_TOKEN", "")
WHISPER_MODEL: str = os.environ.get("WHISPER_MODEL", "small")
WHISPER_DEVICE: str = os.environ.get("WHISPER_DEVICE", "cpu")
TTS_ENGINE: str = os.environ.get("TTS_ENGINE", "edge")
WORK_DIR: Path = Path(os.environ.get("WORK_DIR", "/data/jobs"))
MAX_VIDEO_MINUTES: float = float(os.environ.get("MAX_VIDEO_MINUTES", "15"))
