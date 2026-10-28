#!/usr/bin/env bash
# A fresh checkout for each Docker stage; optional shared BuildKit cache stores
# Git objects across different distribution images (never compiled artifacts).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${WALLET_CORE_DIR:-$ROOT/build/wallet-core}"
REPO="${WALLET_CORE_REPO:-https://github.com/trustwallet/wallet-core.git}"
REF="${WALLET_CORE_REF:-master}"
CACHE="${WALLET_CORE_GIT_CACHE:-}"
REFRESH="${WALLET_CORE_REFRESH:-0}"
command -v git >/dev/null || { echo 'ERROR: Git is missing inside Docker' >&2; exit 2; }
[[ "$REFRESH" == 0 || "$REFRESH" == 1 ]] || { echo 'ERROR: WALLET_CORE_REFRESH must be 0 or 1' >&2; exit 2; }
[[ "$REF" =~ ^[A-Za-z0-9][A-Za-z0-9._/+\-]*$ ]] || { echo 'ERROR: invalid Wallet Core ref' >&2; exit 2; }
if [[ -e "$DIR" || -L "$DIR" ]]; then
  echo "ERROR: upstream destination already exists: $DIR (use a fresh Docker layer)" >&2
  exit 2
fi
mkdir -p -- "$(dirname "$DIR")"

if [[ -z "$CACHE" ]]; then
  # Standalone invocation remains possible without Docker or cache mounts.
  echo "Cloning upstream: $REPO ($REF)"
  git clone --no-checkout "$REPO" "$DIR"
  if ! git -C "$DIR" checkout --detach "$REF"; then
    if ! git -C "$DIR" fetch origin "$REF"; then
      echo "ERROR: Wallet Core reference not found: $REF" >&2
      exit 2
    fi
    git -C "$DIR" checkout --detach FETCH_HEAD
  fi
else
  # The BuildKit mount uses sharing=locked; no concurrent writer can corrupt it.
  # Hash the repository URL to prevent unrelated repositories sharing objects.
  repo_key="$(printf '%s' "$REPO" | sha256sum | cut -d' ' -f1)"
  ref_key="$(printf '%s' "$REF" | sha256sum | cut -d' ' -f1)"
  mirror="$CACHE/$repo_key.git"
  cache_ref="refs/walletcore-cache/$ref_key"
  mkdir -p -- "$CACHE"
  if [[ ! -d "$mirror" ]]; then
    git init --bare --quiet "$mirror"
    git --git-dir="$mirror" remote add origin "$REPO"
  fi
  [[ "$(git --git-dir="$mirror" remote get-url origin)" == "$REPO" ]] || {
    echo 'ERROR: Git cache origin mismatch' >&2; exit 2;
  }
  if [[ "$REFRESH" == 1 ]] || ! git --git-dir="$mirror" show-ref --verify --quiet "$cache_ref"; then
    echo "Fetching Wallet Core ref $REF into shared Git cache"
    fetched=0
    for attempt in 1 2 3; do
      if git --git-dir="$mirror" fetch --quiet --depth=1 origin "$REF"; then
        fetched=1
        break
      fi
      [[ "$attempt" == 3 ]] && break
      sleep "$attempt"
    done
    if [[ "$fetched" != 1 ]]; then
      echo "ERROR: Wallet Core reference not found: $REF" >&2
      exit 2
    fi
    git --git-dir="$mirror" update-ref "$cache_ref" "$(git --git-dir="$mirror" rev-parse FETCH_HEAD^{commit})"
    # Fetch only the named tag (if it exists), not the entire tag namespace.
    # Upstream version detection may depend on `git describe --exact-match`.
    if git --git-dir="$mirror" ls-remote --exit-code origin "refs/tags/$REF" >/dev/null 2>&1; then
      git --git-dir="$mirror" fetch --quiet --depth=1 origin "refs/tags/$REF:refs/tags/$REF"
    fi
  else
    echo "Reusing cached Wallet Core ref $REF (no remote fetch)"
  fi
  # Transfer objects into the Docker layer; NEVER use --shared/alternates,
  # since the BuildKit cache mount disappears after this RUN instruction.
  git init --quiet "$DIR"
  git -C "$DIR" remote add origin "$REPO"
  git -C "$DIR" fetch --quiet --update-shallow --no-tags "$mirror" "$cache_ref"
  git -C "$DIR" checkout --quiet --detach FETCH_HEAD
  # Preserve a cached release tag where present (for git describe/versioning).
  if git --git-dir="$mirror" show-ref --verify --quiet "refs/tags/$REF"; then
    git -C "$DIR" fetch --quiet --update-shallow "$mirror" "refs/tags/$REF:refs/tags/$REF"
  fi
fi

git -C "$DIR" submodule update --init --recursive
HASH="$(git -C "$DIR" rev-parse HEAD)"
printf 'Wallet Core %s at commit %s\n' "$REF" "$HASH"
