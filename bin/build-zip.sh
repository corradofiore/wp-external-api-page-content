#!/usr/bin/env bash
#
# Builds the distributable plugin zip in build/.
#
# The archive contains a single top-level folder named after the plugin slug,
# which is the layout the WordPress.org plugin directory expects.
#
# Usage: bin/build-zip.sh

set -euo pipefail

SLUG="wp-external-api-page-content"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT="$(dirname "$ROOT")"

VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/$SLUG.php" | head -n 1)"

if [ -z "$VERSION" ]; then
	echo "error: could not read the Version header from $SLUG.php" >&2
	exit 1
fi

OUT_DIR="$ROOT/build"
OUT="$OUT_DIR/$SLUG-$VERSION.zip"
STAGE="$(mktemp -d)"

cleanup() {
	rm -rf "$STAGE"
}
trap cleanup EXIT

mkdir -p "$OUT_DIR" "$STAGE"

# Stage the plugin, leaving development and repository files behind.
tar -cf - -C "$PARENT" \
	--exclude="$SLUG/.git" \
	--exclude="$SLUG/.github" \
	--exclude="$SLUG/.vscode" \
	--exclude="$SLUG/build" \
	--exclude="$SLUG/node_modules" \
	--exclude="$SLUG/vendor" \
	--exclude="$SLUG/.wp-env*.json" \
	--exclude="$SLUG/.gitignore" \
	--exclude="$SLUG/.distignore" \
	--exclude="$SLUG/.editorconfig" \
	--exclude="$SLUG/bin" \
	--exclude="$SLUG/phpcs.xml.dist" \
	--exclude="*.DS_Store" \
	"$SLUG" | tar -xf - -C "$STAGE"

rm -f "$OUT"
( cd "$STAGE" && zip -rq "$OUT" "$SLUG" )

echo "Built: $OUT"
echo
unzip -l "$OUT"
