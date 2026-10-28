#!/usr/bin/env bash
# Build a selected upstream PHP release within the selected distro base.
# Never reuse PHP headers or php-config from a different runtime image.
set -euo pipefail

PHP_VERSION="${PHP_VERSION:?PHP_VERSION is required}"
PHP_ZTS="${PHP_ZTS:?PHP_ZTS is required}"
PHP_PREFIX="${PHP_PREFIX:-/opt/php}"
BUILD_JOBS="${BUILD_JOBS:-2}"
PHP_SOURCE_REPO="${PHP_SOURCE_REPO:-https://github.com/php/php-src.git}"

[[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
  echo "ERROR: invalid PHP_VERSION: $PHP_VERSION" >&2
  exit 2
}
[[ "$PHP_ZTS" == 0 || "$PHP_ZTS" == 1 ]] || {
  echo "ERROR: PHP_ZTS must be 0 or 1: $PHP_ZTS" >&2
  exit 2
}
[[ "$PHP_PREFIX" == /* && "$PHP_PREFIX" != / ]] || {
  echo "ERROR: PHP_PREFIX must be an absolute non-root directory" >&2
  exit 2
}
[[ ! -e "$PHP_PREFIX/bin/php" ]] || {
  echo "ERROR: PHP already installed at $PHP_PREFIX" >&2
  exit 2
}

# A stable source path inside disposable Docker RUN containers allows ccache
# to reuse PHP object files after an otherwise unrelated Docker layer miss.
# Outside Docker, keep the original mktemp behavior.
if [[ -n "${PHP_BUILD_WORKDIR:-}" ]]; then
  [[ "$PHP_BUILD_WORKDIR" == /tmp/* && ! -e "$PHP_BUILD_WORKDIR" ]] || {
    echo 'ERROR: PHP_BUILD_WORKDIR must be an unused directory inside /tmp' >&2
    exit 2
  }
  workdir="$PHP_BUILD_WORKDIR"
  mkdir -p -- "$workdir"
else
  workdir="$(mktemp -d /tmp/walletcore-php-src.XXXXXXXX)"
fi
trap 'rm -rf -- "$workdir"' EXIT
tag="php-$PHP_VERSION"
source="$workdir/php-src"
if [[ -n "${PHP_GIT_CACHE:-}" ]]; then
  # Share immutable PHP release tags between OS builds. The resulting working
  # tree is copied into this Docker layer (never an alternates-based checkout).
  repo_key="$(printf '%s' "$PHP_SOURCE_REPO" | sha256sum | cut -d' ' -f1)"
  mirror="$PHP_GIT_CACHE/$repo_key.git"
  mkdir -p -- "$PHP_GIT_CACHE"
  if [[ ! -d "$mirror" ]]; then
    git init --bare --quiet "$mirror"
    git --git-dir="$mirror" remote add origin "$PHP_SOURCE_REPO"
  fi
  [[ "$(git --git-dir="$mirror" remote get-url origin)" == "$PHP_SOURCE_REPO" ]] || {
    echo 'ERROR: PHP Git cache origin mismatch' >&2; exit 2;
  }
  if ! git --git-dir="$mirror" show-ref --verify --quiet "refs/tags/$tag"; then
    echo "Fetching PHP tag $tag into shared Git cache"
    fetched=0
    for attempt in 1 2 3; do
      if git --git-dir="$mirror" fetch --quiet --depth=1 origin "refs/tags/$tag:refs/tags/$tag"; then
        fetched=1; break
      fi
      [[ "$attempt" == 3 ]] && break
      sleep "$attempt"
    done
    if [[ "$fetched" != 1 ]]; then
      echo "ERROR: unable to fetch PHP release tag $tag" >&2; exit 3
    fi
  else
    echo "Reusing cached PHP tag $tag (no remote fetch)"
  fi
  git init --quiet "$source"
  git -C "$source" fetch --quiet --update-shallow "$mirror" "refs/tags/$tag:refs/tags/$tag"
  git -C "$source" checkout --quiet --detach "$tag"
else
  for attempt in 1 2 3; do
    if git clone --quiet --depth 1 --single-branch --branch "$tag" \
        "$PHP_SOURCE_REPO" "$source"; then
      break
    fi
    rm -rf -- "$source"
    if [[ "$attempt" == 3 ]]; then
      echo "ERROR: unable to fetch PHP release tag $tag" >&2
      exit 3
    fi
    sleep "$attempt"
  done
fi
actual_tag="$(git -C "$source" describe --tags --exact-match 2>/dev/null || true)"
[[ "$actual_tag" == "$tag" ]] || {
  echo "ERROR: PHP checkout must point to $tag, got ${actual_tag:-none}" >&2
  exit 2
}
cd "$source"
./buildconf --force
configure=(
  --prefix="$PHP_PREFIX" --disable-all --enable-cli
  --disable-cgi --disable-phpdbg --without-pear
)
if [[ "$PHP_ZTS" == 1 ]]; then
  configure+=(--enable-zts)
fi
./configure "${configure[@]}"
make --jobs="$BUILD_JOBS"
make install
"$PHP_PREFIX/bin/php" -n -r '
  if (PHP_VERSION !== $argv[1] || (int) PHP_ZTS !== (int) $argv[2]) {
    fwrite(STDERR, "Installed PHP ABI does not match requested version/ZTS\n");
    exit(2);
  }
' "$PHP_VERSION" "$PHP_ZTS"
[[ "$("$PHP_PREFIX/bin/php-config" --version)" == "$PHP_VERSION" ]] || {
  echo 'ERROR: php-config version mismatch' >&2
  exit 2
}
