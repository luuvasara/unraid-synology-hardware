#!/bin/bash
# serve.sh <scenario> <port> [theme] - local render harness for SynologyHW.page (build host only, NOT the real webGui).
# Written 2026-10-25 (2026.10.25f; the router dates from 2026.10.25b). Builds a fake box with tests/mkfixture.py and serves the
# page through router.php with php -S on 127.0.0.1. Unraid's own files (the 7.3.4 webGui styles/js and the MarkdownExtra
# library) are NOT in this repo: point WEBGUI_COPY at a local copy holding webGui/styles, webGui/javascript and lib/Markdown*.php
# (copied from the Unraid 7.3.4 extract). Work files go to tests/harness/.work (git-ignored). FAIL=1 makes the status endpoint
# answer 502 (error state screenshots).
set -euo pipefail
H=$(cd "$(dirname "$0")" && pwd); P=$(cd "$H/../.." && pwd)
SC=${1:?scenario}; PORT=${2:?port}; THEME=${3:-black}
WEBGUI_COPY=${WEBGUI_COPY:?set WEBGUI_COPY to a dir with webGui/ and lib/ copied from Unraid 7.3.4}
[ -d "$WEBGUI_COPY/webGui/styles" ] && [ -f "$WEBGUI_COPY/lib/MarkdownExtra.inc.php" ] || { echo "WEBGUI_COPY incomplete: $WEBGUI_COPY"; exit 1; }
W=$H/.work/$SC-$PORT; rm -rf "$W"; mkdir -p "$W/webroot/plugins"
ln -s "$WEBGUI_COPY/webGui" "$W/webroot/webGui"; ln -s "$WEBGUI_COPY/lib" "$W/lib"
ln -s "$P/src/usr/local/emhttp/plugins/synology-hardware" "$W/webroot/plugins/synology-hardware"
python3 -I "$P/tests/mkfixture.py" "$W/fx" "$SC" >"$W/ov.json"
echo "harness: http://127.0.0.1:$PORT/  scenario=$SC theme=$THEME work=$W"
exec env HARNESS_WORK="$W" OVERRIDE="$W/ov.json" SCENARIO="$SC" THEME="$THEME" php -S "127.0.0.1:$PORT" -t "$W/webroot" "$H/router.php"
