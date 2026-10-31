"""Stage 3: translate the segments to Khmer with NLLB-200, locally. No online service is used."""

import functools
import logging

import torch
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

from stages import StageError

log = logging.getLogger(__name__)

MODEL_NAME = "facebook/nllb-200-distilled-600M"
TARGET = "khm_Khmr"
BATCH_SIZE = 12
MAX_TOKENS = 256

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


def translate(segments: list[dict], src_lang: str) -> list[dict]:
    """Fill `km` with the Khmer translation of `text` for every segment and return the segments.

    `src_lang` is the Whisper language code. Khmer sources are copied without loading the model.
    Raises StageError for a language NLLB-200 does not cover or if the model cannot be loaded.
    """
    if src_lang == "km":
        for seg in segments:
            seg["km"] = seg["text"]
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
            seg["km"] = km.strip()
    log.info("Translated %d segments from %s to %s", len(todo), src_code, TARGET)
    return segments


def _translate_batch(texts: list[str], src_code: str) -> list[str]:
    """Translate one batch of texts from `src_code` to Khmer."""
    tokenizer, model = _load_model(MODEL_NAME)
    tokenizer.src_lang = src_code  # sets the language token the encoder sees
    inputs = tokenizer(texts, return_tensors="pt", padding=True, truncation=True, max_length=MAX_TOKENS)
    with torch.inference_mode():
        output = model.generate(
            **inputs,
            forced_bos_token_id=tokenizer.convert_tokens_to_ids(TARGET),
            num_beams=4,
            # Beam search alone loops on short or list-like lines ("Hello, how are you?" became
            # "អ៊ូ! អ៊ូ! អ៊ូ! ..."); blocking repeated 3-grams and capping the length stops it.
            no_repeat_ngram_size=3,
            max_new_tokens=min(MAX_TOKENS, 3 * inputs["input_ids"].shape[1] + 10),
        )
    return tokenizer.batch_decode(output, skip_special_tokens=True)


@functools.cache
def _load_model(name: str) -> tuple[AutoTokenizer, AutoModelForSeq2SeqLM]:
    """Load the NLLB tokenizer and model once per process; the first call downloads them into HF_HOME."""
    try:
        tokenizer = AutoTokenizer.from_pretrained(name)
        model = AutoModelForSeq2SeqLM.from_pretrained(name).eval()
    except Exception as exc:
        log.exception("Could not load translation model %r", name)
        raise StageError(
            "Could not load the translation model. The first run downloads about 2.5 GB, so check the "
            "internet connection and free disk space, then try again."
        ) from exc
    return tokenizer, model
