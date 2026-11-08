"""Stage 3: translate. The tests marked `models` use the real NLLB-200 model (about 2.5 GB on the first run)."""

import re

import pytest

import config
from stages import StageError, translate as translate_module
from stages.translate import WHISPER_TO_NLLB, translate

KHMER_LETTERS = re.compile("[ក-៿]")


def segments(*texts: str) -> list[dict]:
    """Make segments in the shape transcribe() returns."""
    return [{"id": i, "start": float(i), "end": i + 0.9, "text": text} for i, text in enumerate(texts)]


@pytest.fixture
def no_model(monkeypatch: pytest.MonkeyPatch) -> None:
    """Fail the test if the model would be loaded."""
    def refuse(name: str) -> None:
        raise AssertionError("the model must not be loaded")
    monkeypatch.setattr(translate_module, "_load_model", refuse)


def test_khmer_source_is_copied(no_model: None) -> None:
    result = translate(segments("សួស្តី", "អរគុណ"), "km")
    assert [seg["km"] for seg in result] == ["សួស្តី", "អរគុណ"]


def test_khmer_speech_written_in_another_script_is_refused(no_model: None) -> None:
    # What Whisper really wrote for a Khmer video: Sinhala, Bengali and Latin, but no Khmer.
    with pytest.raises(StageError, match="in Khmer already"):
        translate(segments("සිංහල අකුරු", "মাযাযা হা", "miracles"), "km")


def test_only_lines_really_in_khmer_are_kept(no_model: None) -> None:
    result = translate(segments("សួស្តី", "සිංහල", "អរគុណ NASA"), "km")
    assert [seg["km"] for seg in result] == ["សួស្តី", "", "អរគុណ NASA"]


def test_a_translation_with_no_khmer_is_refused(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(translate_module, "_translate_batch", lambda texts, src_code: list(texts))
    with pytest.raises(StageError, match="did not produce any Khmer"):
        translate(segments("Hello", "Goodbye"), "en")


def test_unsupported_language(no_model: None) -> None:
    with pytest.raises(StageError, match="'la'"):
        translate(segments("Lorem ipsum"), "la")


def test_translates_in_batches_and_skips_empty_text(monkeypatch: pytest.MonkeyPatch) -> None:
    calls: list[tuple[int, str]] = []

    def fake_batch(texts: list[str], src_code: str) -> list[str]:
        calls.append((len(texts), src_code))
        return [f" ក:{text} " for text in texts]

    monkeypatch.setattr(translate_module, "_translate_batch", fake_batch)
    texts = [f"line {i}" for i in range(29)]
    texts[3] = "  "
    steps: list[tuple[int, int]] = []
    result = translate(segments(*texts), "ja", lambda done, total: steps.append((done, total)))

    assert calls == [(12, "jpn_Jpan"), (12, "jpn_Jpan"), (4, "jpn_Jpan")]
    assert steps == [(12, 28), (24, 28), (28, 28)]
    assert result[0]["km"] == "ក:line 0"
    assert result[3]["km"] == ""
    assert result[28]["km"] == "ក:line 28"


def test_khmer_word_spaces_are_kept_unless_asked(monkeypatch: pytest.MonkeyPatch) -> None:
    nllb = "ខ្ញុំ ស្រឡាញ់ ភាសា ខ្មែរ។ តម្លៃ 5 ដុល្លារ ឬ ៥ NASA ជួយ!"
    monkeypatch.setattr(translate_module, "_translate_batch", lambda texts, src_code: [nllb for _ in texts])

    assert translate(segments("I love Khmer."), "en")[0]["km"] == nllb
    monkeypatch.setattr(config, "KHMER_SPACES", "remove")
    assert translate(segments("I love Khmer."), "en")[0]["km"] == "ខ្ញុំស្រឡាញ់ភាសាខ្មែរ។ តម្លៃ 5 ដុល្លារឬ ៥ NASA ជួយ!"


def test_language_map_includes_required_codes() -> None:
    required = {"en": "eng_Latn", "zh": "zho_Hans", "th": "tha_Thai", "ja": "jpn_Jpan",
                "ko": "kor_Hang", "fr": "fra_Latn", "vi": "vie_Latn"}
    assert required.items() <= WHISPER_TO_NLLB.items()


@pytest.mark.models
def test_every_mapped_code_is_known_to_nllb() -> None:
    tokenizer, _ = translate_module._load_model(translate_module.MODEL_NAME)
    unknown = [code for code in WHISPER_TO_NLLB.values() if tokenizer.convert_tokens_to_ids(code) == tokenizer.unk_token_id]
    assert unknown == []


@pytest.mark.models
def test_translates_english_to_khmer() -> None:
    result = translate(segments("Hello, how are you today?", "We have a mighty big job to do."), "en")
    for seg in result:
        assert KHMER_LETTERS.search(seg["km"]), f"no Khmer letters in {seg['km']!r}"
        assert not re.search("[A-Za-z]", seg["km"]), f"English left in {seg['km']!r}"


@pytest.mark.models
def test_no_runaway_repetition() -> None:
    # Both lines looped with plain beam search: "អ៊ូ! អ៊ូ! ..." and "៥,៤,៣,២,១,១,១,១,...".
    result = translate(segments("Hello, how are you?", "Five, four, three, two, one, and liftoff"), "en")
    for seg in result:
        words = re.split(r"[\s,!?។]+", seg["km"])
        assert max(words.count(word) for word in words) <= 2, f"repeats in {seg['km']!r}"


def test_model_load_failure_is_clear(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(translate_module, "MODEL_NAME", "/no/such/model")
    with pytest.raises(StageError, match="Could not load the translation model"):
        translate(segments("Hello"), "en")
