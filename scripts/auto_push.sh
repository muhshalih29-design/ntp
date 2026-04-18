#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Not a git repository: $repo_root" >&2
  exit 1
fi

if ! command -v fswatch >/dev/null 2>&1; then
  echo "Missing dependency: fswatch" >&2
  echo "Install on macOS: brew install fswatch" >&2
  exit 1
fi

debounce_seconds="${DEBOUNCE_SECONDS:-3}"

echo "Auto-push running in: $repo_root"
echo "Debounce: ${debounce_seconds}s"
echo "Stop: Ctrl+C"

event_counter=0

fswatch -0 -r \
  --exclude='\.git/' \
  --exclude='\.DS_Store$' \
  --exclude='(^|/)\.env$' \
  . | while IFS= read -r -d '' _path; do
    event_counter=$((event_counter + 1))

    # Debounce: wait a bit, then drain any queued events.
    sleep "$debounce_seconds"
    while IFS= read -r -t 0 -d '' _more; do
      IFS= read -r -d '' _more || true
    done

    ts="$(date '+%Y-%m-%d %H:%M:%S')"
    msg="Auto push (${event_counter} events) ${ts}"

    # Commit + push only when there is something real to commit.
    "${repo_root}/scripts/push.sh" "$msg" || true
  done

