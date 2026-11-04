"""Pipeline stages. Each module has one job and can be run and tested on its own."""

import re

KHMER_LETTER = re.compile("[ក-៿᧠-᧿]")


class StageError(Exception):
    """A failure the user can act on. The message is short and safe to show in the UI."""


def is_khmer(text: str) -> bool:
    """True if the text has Khmer letters or digits."""
    return KHMER_LETTER.search(text) is not None


def has_other_script_only(text: str) -> bool:
    """True if the text has letters and none of them are Khmer, so the Khmer voice cannot read it."""
    return not is_khmer(text) and any(ch.isalpha() for ch in text)
