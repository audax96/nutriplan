#!/bin/bash
set -eo pipefail
export PATH="$HOME/.docker/bin:/usr/local/bin:/opt/homebrew/bin:/Applications/Docker.app/Contents/Resources/bin:$PATH"
DIR="$HOME/Backups/nutriplan"
mkdir -p "$DIR"
cd "$HOME/Projects/nutriplan"
OUT="$DIR/nutriplan-$(date +%F).sql.gz"
docker compose exec -T db sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction nutriplan' | gzip > "$OUT.tmp"
mv "$OUT.tmp" "$OUT"
find "$DIR" -name "*.sql.gz" -mtime +14 -delete
echo "Backup saved: $OUT"
