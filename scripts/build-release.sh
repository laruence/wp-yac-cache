#!/usr/bin/env bash
# Build the release zip for WordPress.org plugin submission.
#
# Whitelist, not blacklist: only files that belong in the installed plugin
# are packaged. Tests, docs and development files never get in by accident.
#
# Usage: scripts/build-release.sh [output.zip]   (default dist/yac-ocache.zip)
set -euo pipefail

cd "$(dirname "$0")/.."

FILES=(yac-ocache.php object-cache.php readme.txt LICENSE assets/yac-ocache.css assets/yac-ocache-notice.js assets/yac-ocache-admin.js)
OUT="${1:-dist/yac-ocache.zip}"

for f in "${FILES[@]}"; do
	[ -f "$f" ] || { echo "error: release file missing: $f" >&2; exit 1; }
done

php -l yac-ocache.php
php -l object-cache.php

# wordpress.org rejects readmes larger than 10k
size=$(wc -c < readme.txt)
[ "$size" -le 10240 ] || { echo "error: readme.txt is $size bytes (limit 10240)" >&2; exit 1; }

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
zip -9 -X "$OUT" "${FILES[@]}"

echo "---"
unzip -l "$OUT"
echo "built: $OUT"
