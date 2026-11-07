#!/bin/bash
set -euo pipefail
cd "$HOME/Projects/nutriplan/app"

flutter build web --release --dart-define=API_URL=https://api.nutriplan.stream

if grep -q 'FLUTTER_BASE_HREF' build/web/index.html; then
  echo "ERROR: build/web/index.html is still the raw template. Not deploying."
  exit 1
fi
if [ ! -f build/web/main.dart.js ]; then
  echo "ERROR: main.dart.js missing. Not deploying."
  exit 1
fi

wrangler deploy --assets=build/web --name empty-pine-24e6 --compatibility-date=2026-11-01

sleep 5
if curl -s https://app.nutriplan.stream | grep -q 'FLUTTER_BASE_HREF'; then
  echo "WARNING: live site still shows the raw template."
  exit 1
fi
echo "Deployed and verified: $(curl -s https://app.nutriplan.stream | grep -o '<base href="[^"]*">')"
