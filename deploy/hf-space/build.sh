#!/usr/bin/env bash
# Assembles the Hugging Face Space folder in build/hf-space: web/ and worker/ (only files Git tracks or
# would track, so no .env, vendor, uploads, job folders or models), plus this folder's Dockerfile and scripts.
# Run from anywhere inside the repository: bash deploy/hf-space/build.sh
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
out="$root/build/hf-space"
here="$root/deploy/hf-space"

rm -rf "$out"
mkdir -p "$out"
cd "$root"

git ls-files -z --cached --others --exclude-standard -- web worker | while IFS= read -r -d '' file; do
    [ -f "$file" ] || continue  # tracked but deleted in the working tree
    mkdir -p "$out/$(dirname "$file")"
    cp -p "$file" "$out/$file"
done

cp "$here/Dockerfile" "$here/start.sh" "$here/preload.py" "$out/"
cp "$here/space-README.md" "$out/README.md"
printf '%s\n' '**/__pycache__' '.pytest_cache' > "$out/.dockerignore"

echo "Space folder ready: $out ($(find "$out" -type f | wc -l) files)"
