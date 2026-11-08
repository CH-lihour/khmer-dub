"""Stage 3: translate the segments to Khmer with NLLB-200, locally. No online service is used."""

import functools
import logging
import os
import re
import shutil
from collections.abc import Callable
from pathlib import Path

import ctranslate2
from transformers import AutoTokenizer

import config
from stages import StageError, is_khmer

log = logging.getLogger(__name__)

MODEL_NAME = "facebook/nllb-200-distilled-600M"
TARGET = "khm_Khmr"
BATCH_SIZE = 12
MAX_TOKENS = 256
ALREADY_KHMER = "This video seems to be in Khmer already, so there is nothing to translate. Upload a video in another language."
NO_KHMER = "The translation did not produce any Khmer text. Try a video with clearer speech."

# Whisper language code -> NLLB-200 (FLORES-200) code. Whisper languages NLLB lacks
# (Latin, Breton, Hawaiian) are left out on purpose and get a clear error.
WHISPER_TO_NLLB: dict[str, str] = {
    "af": "afr_Latn", "am": "amh_Ethi", "ar": "arb_Arab", "as": "asm_Beng", "az": "azj_Latn",
    "ba": "bak_Cyrl", "be": "bel_Cyrl", "bg": "bul_Cyrl", "bn": "ben_Beng", "bo": "bod_Tibt",
    "bs": "bos_Latn", "ca": "cat_Latn", "cs": "ces_Latn", "cy": "cym_Latn", "da": "dan_Latn",
    "de": "deu_Latn", "el": "ell_Grek", "en": "eng_Latn", "es": "spa_Latn", "et": "est_Latn",
    "eu": "eus_Latn", "fa": "pes_Arab", "fi": "fin_Latn", "fo": "fao_Latn", "fr": "fra_Latn",
    "gl": "glg_Latn", "gu": "guj_Gujr", "ha": "hau_Latn", "he": "heb_Hebr", "hi": "hin_Deva",
    "hr": "hrv_Latn", "ht": "hat_Latn", "hu": "hun_Latn", "hy": "hye_Armn", "id": "ind_Latn",
    "is": "isl_Latn", "it": "ita_Latn", "ja": "jpn_Jpan", "jw": "jav_Latn", "ka": "kat_Geor",
    "kk": "kaz_Cyrl", "km": "khm_Khmr", "kn": "kan_Knda", "ko": "kor_Hang", "lb": "ltz_Latn",
    "ln": "lin_Latn", "lo": "lao_Laoo", "lt": "lit_Latn", "lv": "lvs_Latn", "mg": "plt_Latn",
    "mi": "mri_Latn", "mk": "mkd_Cyrl", "ml": "mal_Mlym", "mn": "khk_Cyrl", "mr": "mar_Deva",
    "ms": "zsm_Latn", "mt": "mlt_Latn", "my": "mya_Mymr", "ne": "npi_Deva", "nl": "nld_Latn",
    "nn": "nno_Latn", "no": "nob_Latn", "oc": "oci_Latn", "pa": "pan_Guru", "pl": "pol_Latn",
    "ps": "pbt_Arab", "pt": "por_Latn", "ro": "ron_Latn", "ru": "rus_Cyrl", "sa": "san_Deva",
    "sd": "snd_Arab", "si": "sin_Sinh", "sk": "slk_Latn", "sl": "slv_Latn", "sn": "sna_Latn",
    "so": "som_Latn", "sq": "als_Latn", "sr": "srp_Cyrl", "su": "sun_Latn", "sv": "swe_Latn",
    "sw": "swh_Latn", "ta": "tam_Taml", "te": "tel_Telu", "tg": "tgk_Cyrl", "th": "tha_Thai",
    "tk": "tuk_Latn", "tl": "tgl_Latn", "tr": "tur_Latn", "tt": "tat_Cyrl", "uk": "ukr_Cyrl",
    "ur": "urd_Arab", "uz": "uzn_Latn", "vi": "vie_Latn", "yi": "ydd_Hebr", "yo": "yor_Latn",
    "yue": "yue_Hant", "zh": "zho_Hans",
}


def translate(segments: list[dict], src_lang: str, on_step: Callable[[int, int], None] | None = None) -> list[dict]:
    """Fill `km` with the Khmer translation of `text` for every segment and return the segments.

    `src_lang` is the Whisper language code. Khmer sources are copied without loading the model, but
    only lines really written in Khmer: Whisper knows when speech is Khmer, yet writes it as garbage
    in other scripts. `on_step(done, total)` reports the lines translated so far. Raises StageError
    if that leaves nothing, if no line came out in Khmer, for a language NLLB-200 does not cover, or
    if the model cannot be loaded.
    """
    if src_lang == "km":
        for seg in segments:
            seg["km"] = seg["text"] if is_khmer(seg["text"]) else ""
        if not any(seg["km"] for seg in segments):
            raise StageError(ALREADY_KHMER)
        return segments

    src_code = WHISPER_TO_NLLB.get(src_lang)
    if src_code is None:
        raise StageError(f"Translating from this language ('{src_lang}') to Khmer is not supported.")

    todo = [seg for seg in segments if seg["text"].strip()]
    for seg in segments:
        seg["km"] = ""
    for start in range(0, len(todo), BATCH_SIZE):
        batch = todo[start:start + BATCH_SIZE]
        for seg, km in zip(batch, _translate_batch([seg["text"] for seg in batch], src_code), strict=True):
            seg["km"] = join_khmer_words(km.strip()) if config.KHMER_SPACES == "remove" else km.strip()
        if on_step:
            on_step(min(start + BATCH_SIZE, len(todo)), len(todo))
    if not any(is_khmer(seg["km"]) for seg in segments):
        raise StageError(NO_KHMER)
    log.info("Translated %d segments from %s to %s", len(todo), src_code, TARGET)
    return segments


# A Khmer letter or sign (U+1780 to U+17D3, and U+17DD), not punctuation (។ ៖ ...) or a digit.
_BETWEEN_KHMER_WORDS = re.compile(r"(?<=[ក-៓៝]) +(?=[ក-៓៝])")


def join_khmer_words(text: str) -> str:
    """Remove the spaces NLLB puts between Khmer words, as Khmer is written without them.

    Only a space with a Khmer letter on both sides goes, so the spaces next to punctuation,
    digits and Latin words stay.
    """
    return _BETWEEN_KHMER_WORDS.sub("", text)


def _translate_batch(texts: list[str], src_code: str) -> list[str]:
    """Translate one batch of texts from `src_code` to Khmer."""
    tokenizer, model = _load_model(MODEL_NAME)
    tokenizer.src_lang = src_code  # sets the language token the encoder sees
    source = [tokenizer.convert_ids_to_tokens(tokenizer.encode(text, truncation=True, max_length=MAX_TOKENS)) for text in texts]
    results = model.translate_batch(
        source,
        target_prefix=[[TARGET]] * len(source),
        beam_size=4,
        # Beam search alone loops on short or list-like lines ("Hello, how are you?" became
        # "អ៊ូ! អ៊ូ! អ៊ូ! ..."); blocking repeated 3-grams and capping the length stops it.
        no_repeat_ngram_size=3,
        max_decoding_length=min(MAX_TOKENS, 3 * max(map(len, source)) + 10),
    )
    # Each hypothesis starts with the target language token.
    return [tokenizer.decode(tokenizer.convert_tokens_to_ids(result.hypotheses[0][1:]), skip_special_tokens=True)
            for result in results]


def _converted_path(name: str) -> Path:
    """Where the CTranslate2 copy of model `name` is kept, next to the downloaded models."""
    home = Path(os.environ.get("HF_HOME", "~/.cache/huggingface")).expanduser()
    return home / "ctranslate2" / f"{name.strip('/').replace('/', '--')}-int8"


@functools.cache
def _load_model(name: str) -> tuple[AutoTokenizer, ctranslate2.Translator]:
    """Load the NLLB tokenizer and model once per process.

    The model runs in CTranslate2 with int8 weights, about 8 times faster on CPU than in
    transformers and with almost the same output. The first call downloads the model into HF_HOME
    and converts it there (about 30 s and 630 MB, once).
    """
    try:
        tokenizer = AutoTokenizer.from_pretrained(name)
        converted = _converted_path(name)
        if not (converted / "model.bin").exists():
            log.info("Converting %s for CTranslate2 into %s (once)", name, converted)
            partial = converted.with_name(converted.name + ".partial")  # never leave a half-written model in place
            shutil.rmtree(partial, ignore_errors=True)
            ctranslate2.converters.TransformersConverter(name).convert(str(partial), quantization="int8")
            partial.rename(converted)
        model = ctranslate2.Translator(str(converted), device="cpu", compute_type="int8")
    except Exception as exc:
        log.exception("Could not load translation model %r", name)
        raise StageError(
            "Could not load the translation model. The first run downloads about 2.5 GB, so check the "
            "internet connection and free disk space, then try again."
        ) from exc
    return tokenizer, model
