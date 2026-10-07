#!/usr/bin/env bash
#
# Builds the distributable plugin zip in build/.
#
# The archive contains a single top-level folder named after the plugin slug,
# which is the layout the WordPress.org plugin directory expects.
#
# Usage: bin/build-zip.sh

set -euo pipefail

# The WordPress.org slug. It is derived from the Plugin Name header and becomes
# the plugin folder name on installed sites, so the archive root must use it.
SLUG="external-api-page-content"

# The working directory keeps its own name (it is also the Git repository name).
SRC_DIR="$(basename "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)")"
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
	--exclude="$SRC_DIR/.git" \
	--exclude="$SRC_DIR/.github" \
	--exclude="$SRC_DIR/.vscode" \
	--exclude="$SRC_DIR/build" \
	--exclude="$SRC_DIR/node_modules" \
	--exclude="$SRC_DIR/vendor" \
	--exclude="$SRC_DIR/.wp-env*.json" \
	--exclude="$SRC_DIR/.gitignore" \
	--exclude="$SRC_DIR/.distignore" \
	--exclude="$SRC_DIR/.editorconfig" \
	--exclude="$SRC_DIR/bin" \
	--exclude="$SRC_DIR/wporg-assets" \
	--exclude="$SRC_DIR/phpcs.xml.dist" \
	--exclude="*.DS_Store" \
	"$SRC_DIR" | tar -xf - -C "$STAGE"

# Ship the folder under the slug, not under the working directory name.
mv "$STAGE/$SRC_DIR" "$STAGE/$SLUG"

rm -f "$OUT"
( cd "$STAGE" && zip -rq "$OUT" "$SLUG" )

echo "Built: $OUT"
echo
unzip -l "$OUT"
