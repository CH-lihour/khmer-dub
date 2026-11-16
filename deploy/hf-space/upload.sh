#!/usr/bin/env bash
# Uploads build/hf-space to your Hugging Face Space, using the hf command inside the worker container
# (no local Python needed). Asks for a Hugging Face write token and does not save it anywhere.
# Usage: bash deploy/hf-space/upload.sh <your-hf-name>/<space-name>
set -euo pipefail

space="${1:?Usage: bash deploy/hf-space/upload.sh <your-hf-name>/<space-name>}"
root="$(git rev-parse --show-toplevel)"
cd "$root"

if [ ! -f build/hf-space/Dockerfile ]; then
    echo "Run bash deploy/hf-space/build.sh first." >&2
    exit 1
fi

read -rsp "Hugging Face write token (input hidden): " HF_TOKEN
echo
export HF_TOKEN

docker compose run --rm --no-deps -e HF_TOKEN -v "$root/build/hf-space:/upload:ro" worker \
    hf upload "$space" /upload . --repo-type space --commit-message "Update Khmer Dub"

echo "Uploaded. The Space rebuilds now: https://huggingface.co/spaces/$space"
