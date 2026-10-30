"""HTTP endpoints of the dubbing worker.

Phase 0 only exposes the health check; the job endpoints of the API contract
are added in Phase 5.
"""

from fastapi import FastAPI

app = FastAPI(title="Khmer dub worker")


@app.get("/health")
def health() -> dict[str, bool]:
    """Liveness probe for the Docker healthcheck. Needs no token."""
    return {"ok": True}
