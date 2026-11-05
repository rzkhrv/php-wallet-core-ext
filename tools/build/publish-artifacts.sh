#!/usr/bin/env bash
# Stage a verified Docker export and publish it only after a complete success.
# No empty artifacts/ directory, partial module or stale .so on failure.
set -euo pipefail
[[ $# -ge 3 ]] || { echo 'usage: publish-artifacts.sh ARTIFACT_DIR LOG_FILE docker buildx ...' >&2; exit 2; }
artifacts="$1"
log="$2"
shift 2
case_dir="$(dirname -- "$artifacts")"
[[ "$(basename -- "$artifacts")" == artifacts ]] || { echo 'ERROR: expected artifacts directory' >&2; exit 2; }
mkdir -p -- "$case_dir/logs"
incoming="$(mktemp -d "$case_dir/.incoming.XXXXXXXX")"
old="$case_dir/.publish-previous"
[[ ! -e "$old" && ! -L "$old" ]] || { echo "ERROR: unfinished artifact publication: $old" >&2; rmdir "$incoming"; exit 2; }
restore() {
  if [[ -d "$old" ]]; then
    if [[ -d "$artifacts" ]]; then rm -rf -- "$artifacts"; fi
    mv -- "$old" "$artifacts"
  fi
  rm -rf -- "$incoming"
}
trap restore EXIT
bash tools/build/capture-build-log.sh "$log" "$@" \
  --output "type=local,dest=$incoming" .
for name in walletcore.so walletcore.stub.php abi.json build-info.json; do
  [[ -s "$incoming/$name" ]] || { echo "ERROR: incomplete export: $name" >&2; exit 2; }
done
if [[ -e "$artifacts" ]]; then mv -- "$artifacts" "$old"; fi
mv -- "$incoming" "$artifacts"
if [[ -d "$old" ]]; then rm -rf -- "$old"; fi
trap - EXIT
