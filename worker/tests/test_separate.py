"""Optional stage: separate. The test marked `models` uses the real Demucs model (about 80 MB on the first run)."""

import wave
from pathlib import Path

import pytest

from stages import StageError, separate as separate_module
from stages.separate import separate


@pytest.mark.models
def test_separate_writes_the_sound_without_speech(tone_video: Path, tmp_path: Path) -> None:
    steps: list[tuple[int, int]] = []
    out = separate(tone_video, tmp_path / "background.wav", lambda done, total: steps.append((done, total)))

    with wave.open(str(out), "rb") as wav:
        assert (wav.getnchannels(), wav.getframerate(), wav.getsampwidth()) == (1, 24000, 2)
        assert wav.getnframes() / 24000 == pytest.approx(2.0, abs=0.05)
    assert steps[-1] == (2, 2)


def test_model_load_failure_is_clear(tone_video: Path, tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(separate_module, "MODEL_NAME", "no-such-model")
    with pytest.raises(StageError, match="Could not load the model that removes the original speech"):
        separate(tone_video, tmp_path / "background.wav")
