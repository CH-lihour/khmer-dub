"""Phase 0 checks: the toolchain inside the worker image is complete."""

import importlib
import shutil
import subprocess

import pytest
from fastapi.testclient import TestClient

from app import app


@pytest.mark.parametrize(
    "module",
    ["torch", "faster_whisper", "ctranslate2", "transformers", "sentencepiece", "edge_tts", "pydub", "fastapi"],
)
def test_imports(module: str) -> None:
    importlib.import_module(module)


def test_torch_is_cpu_build() -> None:
    import torch

    assert torch.version.cuda is None


@pytest.mark.parametrize("tool", ["ffmpeg", "ffprobe"])
def test_ffmpeg_installed(tool: str) -> None:
    assert shutil.which(tool), f"{tool} not on PATH"
    subprocess.run([tool, "-version"], check=True, capture_output=True)


def test_health() -> None:
    response = TestClient(app).get("/health")
    assert response.status_code == 200
    assert response.json() == {"ok": True}
