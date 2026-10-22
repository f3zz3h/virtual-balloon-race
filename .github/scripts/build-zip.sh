#!/usr/bin/env bash
# Build the installable plugin zip: dist/virtual-balloon-race-<version>.zip containing a
# virtual-balloon-race/ folder, without the development files listed in .distignore.
#
# Usage: build-zip.sh [expected-version]
# With an expected version (e.g. from a v1.7.0 tag), fail unless the plugin header and
# VBR_VERSION both say that version.
set -euo pipefail

SLUG="virtual-balloon-race"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MAIN="$ROOT/$SLUG.php"

header_version="$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN" | head -1)"
constant_version="$(sed -n "s/^define( 'VBR_VERSION', '\([^']*\)' );/\1/p" "$MAIN" | head -1)"

if [ -z "$header_version" ] || [ "$header_version" != "$constant_version" ]; then
	echo "Version mismatch: plugin header says '$header_version', VBR_VERSION says '$constant_version'." >&2
	exit 1
fi

if [ -n "${1:-}" ] && [ "$1" != "$header_version" ]; then
	echo "The tag is for version $1, but the plugin says $header_version. Bump the version (header and VBR_VERSION) first." >&2
	exit 1
fi

DIST="$ROOT/dist"
STAGE="$DIST/stage"
ZIP="$DIST/$SLUG-$header_version.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE/$SLUG"
rsync -a --exclude-from="$ROOT/.distignore" --exclude dist "$ROOT/" "$STAGE/$SLUG/"

# Refresh the translation template from the code being shipped (needs WP-CLI; otherwise the committed one is used).
if command -v wp > /dev/null; then
	wp i18n make-pot "$STAGE/$SLUG" "$STAGE/$SLUG/languages/$SLUG.pot" --slug="$SLUG" --domain="$SLUG" \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/f3zz3h/virtual-balloon-race/issues"}' --quiet
	echo "Translation template refreshed ($(grep -c '^msgid "' "$STAGE/$SLUG/languages/$SLUG.pot") strings)"
fi
if command -v zip > /dev/null; then
	(cd "$STAGE" && zip -qr -X "$ZIP" "$SLUG")
else
	# No zip program (e.g. a minimal WSL): Python's zipfile does the same job.
	python3 -c 'import shutil, sys; shutil.make_archive(sys.argv[1][:-4], "zip", sys.argv[2], sys.argv[3])' "$ZIP" "$STAGE" "$SLUG"
fi
rm -rf "$STAGE"

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1)), version $header_version"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{ echo "zip=$ZIP"; echo "version=$header_version"; echo "name=$(basename "$ZIP")"; } >> "$GITHUB_OUTPUT"
fi
