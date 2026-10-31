"""Pipeline stages. Each module has one job and can be run and tested on its own."""


class StageError(Exception):
    """A failure the user can act on. The message is short and safe to show in the UI."""
