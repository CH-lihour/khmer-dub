"""Download and prepare every model while the Space image builds, so the Space starts without downloads.

Uses the stages' own loaders, so the files land exactly where the worker looks for them (HF_HOME, TORCH_HOME).
"""

import sys

sys.path.insert(0, "/app/worker")

import config  # noqa: E402
from stages import separate, transcribe, translate, tts  # noqa: E402


def main() -> None:
    """Load each model once: Whisper, NLLB (converted for CTranslate2), Demucs and the offline MMS voice."""
    print(f"Whisper {config.WHISPER_MODEL}", flush=True)
    transcribe._load_model(config.WHISPER_MODEL, "cpu")
    print(f"NLLB {translate.MODEL_NAME}", flush=True)
    translate._load_model(translate.MODEL_NAME)
    print(f"Demucs {separate.MODEL_NAME}", flush=True)
    separate._load_model(separate.MODEL_NAME)
    print(f"MMS {tts.MMS_MODEL}", flush=True)
    tts._load_mms(tts.MMS_MODEL)
    print("All models ready", flush=True)


if __name__ == "__main__":
    main()
