#!/usr/bin/env bash
# Crawl N pages of the site (URLs from the sitemap) and track Yac key-slot
# usage before/along/after. The probe is a token-guarded PHP file executed
# by an FPM worker, because Yac shared memory is an anon mmap inherited
# from the FPM master — a CLI process cannot read those counters.
#
# Usage: tests/crawl.sh [pages]   (default 100)
#
# Target site is configured via env vars, e.g.:
#   WP_YAC_CRAWL_HOST=user@example.com WP_YAC_CRAWL_DOCROOT=/var/www/html \
#   WP_YAC_CRAWL_SITE=https://example.com tests/crawl.sh
set -euo pipefail

HOST=${WP_YAC_CRAWL_HOST:?set WP_YAC_CRAWL_HOST (ssh target, e.g. user@example.com)}
DOCROOT=${WP_YAC_CRAWL_DOCROOT:?set WP_YAC_CRAWL_DOCROOT (web root on the target)}
SITE=${WP_YAC_CRAWL_SITE:?set WP_YAC_CRAWL_SITE (public URL of the site)}
PAGES=${1:-100}
STEP=20
PROBE=wp-yac-slotprobe.php

TOKEN=$(head -c 16 /dev/urandom | xxd -p)

cleanup() { ssh "$HOST" "rm -f $DOCROOT/$PROBE" 2>/dev/null || true; }
trap cleanup EXIT

# --- 1. collect URLs: sitemap index -> sub-sitemaps -> <loc> list -----------

URLFILE=$(mktemp)
for sub in $(curl -s "$SITE/sitemaps.xml" | grep -o "$SITE/[a-z_-]*sitemap[^<]*\.xml"); do
	curl -s "$sub" | grep -o '<loc>[^<]*</loc>' | sed 's/<[^>]*>//g' >> "$URLFILE"
done
TOTAL=$(wc -l < "$URLFILE" | tr -d ' ')
echo "collected $TOTAL URLs from sitemap; crawling $PAGES"

# --- 2. deploy token-guarded probe (outputs Yac counters as JSON) -----------

PROBE_SRC=$(mktemp)
cat > "$PROBE_SRC" <<EOF
<?php
if ( ! isset( \$_GET['token'] ) || \$_GET['token'] !== '$TOKEN' ) { status_header( 404 ); exit; }
\$y = new Yac();
\$i = \$y->info();
echo json_encode( array(
	'slots_used' => \$i['slots_used'],
	'entries'    => count( \$y->dump( -1 ) ),
	'hits'       => \$i['hits'],
	'miss'       => \$i['miss'],
) );
EOF
scp -q "$PROBE_SRC" "$HOST:$DOCROOT/$PROBE"
ssh "$HOST" "chmod 644 $DOCROOT/$PROBE"

probe() { curl -s "$SITE/$PROBE?token=$TOKEN"; }

# --- 3. crawl ---------------------------------------------------------------

echo "baseline:  $(probe)"

i=0
while IFS= read -r url && [ "$i" -lt "$PAGES" ]; do
	code=$(curl -s -o /dev/null -w '%{http_code}' -A 'wp-yac-crawl/1.0' "$url")
	i=$((i + 1))
	if [ $(( i % STEP )) -eq 0 ]; then
		echo "after $(printf '%3d' "$i") pages (last [$code] $url): $(probe)"
	fi
done < "$URLFILE"

echo "final:     $(probe)"
echo "done: $i pages crawled"
