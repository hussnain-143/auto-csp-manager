#!/usr/bin/env bash
#
# Build the distributable plugin ZIP.
#
#   bin/build-release.sh            # build build/auto-csp-manager-<version>.zip
#   bin/build-release.sh --force    # overwrite an existing artefact
#
# The package is built from an explicit allowlist rather than by excluding
# things. An exclude list fails open: a new development file that nobody
# remembered to list ships to every user. An allowlist fails closed — a new
# runtime file that nobody added is caught by the asset and include checks
# below, which is a build failure rather than a silent leak.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"

SLUG="auto-csp-manager"
MAIN="$SLUG.php"
BUILD_DIR="$ROOT/build"

die() { printf '\n  ERROR: %s\n\n' "$1" >&2; exit 1; }
ok()  { printf '  ok    %s\n' "$1"; }

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

# ---------------------------------------------------------------------
# 1. Version, from the one place that is authoritative at runtime
# ---------------------------------------------------------------------
echo "== version"

[ -f "$MAIN" ] || die "main plugin file $MAIN not found; run this from the plugin root"

VERSION="$(sed -n "s/^define( 'ACSP_VERSION', '\([^']*\)' );/\1/p" "$MAIN" | head -1)"
[ -n "$VERSION" ] || die "could not read ACSP_VERSION from $MAIN"

HEADER_VERSION="$(sed -n 's/^ \* Version: *\(.*[^ ]\) *$/\1/p' "$MAIN" | head -1)"
README_VERSION="$(sed -n 's/^Stable tag: *\(.*[^ ]\) *$/\1/p' readme.txt | head -1)"

ok "ACSP_VERSION     $VERSION"

[ "$HEADER_VERSION" = "$VERSION" ] \
	|| die "plugin header says '$HEADER_VERSION' but ACSP_VERSION is '$VERSION'"
ok "plugin header    $HEADER_VERSION"

[ "$README_VERSION" = "$VERSION" ] \
	|| die "readme.txt stable tag says '$README_VERSION' but ACSP_VERSION is '$VERSION'"
ok "readme stable tag $README_VERSION"

grep -q "^= $VERSION =" readme.txt \
	|| die "readme.txt has no changelog entry for $VERSION"
ok "changelog entry  = $VERSION ="

# ---------------------------------------------------------------------
# 2. What ships. Everything else is development-only, by definition.
# ---------------------------------------------------------------------
FILES=(
	"$MAIN"
	"readme.txt"
	"uninstall.php"
)

DIRS=(
	"includes"
	"assets/css"
	"assets/js"
	"assets/brand"
	"languages"
)

# ---------------------------------------------------------------------
# 3. Staging
# ---------------------------------------------------------------------
echo "== staging"

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/acsp-build.XXXXXXXX")"
# Clean up on any exit, successful or not, so a failed build leaves nothing.
trap 'rm -rf "$STAGE"' EXIT

# The ZIP must contain exactly one top-level directory named for the slug,
# so that unzipping into wp-content/plugins/ produces the right path and
# WordPress's uploader does not end up with a nested folder.
PKG="$STAGE/$SLUG"
mkdir -p "$PKG"

for f in "${FILES[@]}"; do
	[ -f "$f" ] || die "expected file missing: $f"
	cp "$f" "$PKG/$f"
done

for d in "${DIRS[@]}"; do
	[ -d "$d" ] || die "expected directory missing: $d"
	mkdir -p "$PKG/$d"
	# -a preserves the tree below the directory; the trailing /. copies the
	# contents rather than nesting the directory inside itself.
	cp -a "$d/." "$PKG/$d/"
done

# Local noise can sit inside an otherwise shippable directory.
find "$PKG" \( -name '.DS_Store' -o -name '._*' -o -name 'Thumbs.db' \
	-o -name '*.orig' -o -name '*.rej' -o -name '*.bak' -o -name '*.log' \) -delete

ok "staged at $PKG"

# ---------------------------------------------------------------------
# 4. Verify the staged tree is actually a working plugin
# ---------------------------------------------------------------------
echo "== verifying the staged package"

[ -f "$PKG/$MAIN" ] || die "main plugin file missing from the package"
ok "main file present"

# 4a. Every require_once in the bootstrap must resolve inside the package.
MISSING_INC=0
while IFS= read -r inc; do
	if [ ! -f "$PKG/$inc" ]; then
		echo "    missing include: $inc" >&2
		MISSING_INC=1
	fi
done < <(sed -n "s/^require_once ACSP_DIR \. '\([^']*\)';/\1/p" "$PKG/$MAIN")
[ "$MISSING_INC" -eq 0 ] || die "the bootstrap requires files that are not in the package"
ok "every require_once resolves"

# 4b. Every asset the code enqueues or links must exist in the package.
#     Covers wp_enqueue_*( ACSP_URL . '...' ) and ACSP_URL . 'assets/...'.
MISSING_ASSET=0
ASSET_COUNT=0
while IFS= read -r asset; do
	ASSET_COUNT=$((ASSET_COUNT + 1))
	if [ ! -f "$PKG/$asset" ]; then
		echo "    missing asset: $asset" >&2
		MISSING_ASSET=1
	fi
done < <(grep -rhoE "ACSP_URL \. '(assets/[^']+)'" "$PKG/includes" "$PKG/$MAIN" \
	| sed -E "s/.*'(assets\/[^']+)'.*/\1/" \
	| grep -v '/$' \
	| sort -u)

[ "$MISSING_ASSET" -eq 0 ] || die "the code references assets that are not in the package"
[ "$ASSET_COUNT" -gt 0 ]   || die "no asset references found — the detection above is broken"
ok "all $ASSET_COUNT referenced assets present"

# 4c. brand_url() builds its path at runtime, so check those names too.
MISSING_BRAND=0
while IFS= read -r svg; do
	if [ ! -f "$PKG/assets/brand/$svg" ]; then
		echo "    missing brand asset: $svg" >&2
		MISSING_BRAND=1
	fi
done < <(grep -rhoE "brand_url\( '[^']+' \)" "$PKG/includes" \
	| sed -E "s/.*'([^']+)'.*/\1/" | sort -u)
[ "$MISSING_BRAND" -eq 0 ] || die "brand_url() references a file that is not in the package"
ok "all brand_url() assets present"

# 4d. Nothing development-only may have crept in.
STRAY=0
while IFS= read -r bad; do
	echo "    development file in package: ${bad#"$PKG"/}" >&2
	STRAY=1
done < <(find "$PKG" \( \
	-name 'phpunit*.xml*' -o -name 'phpcs*.xml*' -o -name 'composer.*' \
	-o -name 'Dockerfile*' -o -name 'docker-compose*' -o -name '*.dist' \
	-o -name 'AUDIT.md' -o -name 'RELEASE-CHECKLIST.md' -o -name '.distignore' \
	-o -name '.gitignore' -o -name '.DS_Store' -o -name '*.zip' \
	-o -type d \( -name 'tests' -o -name 'bin' -o -name 'vendor' -o -name '.git' \
	-o -name '.github' -o -name 'node_modules' -o -name '*brand-kit*' \) \) )
[ "$STRAY" -eq 0 ] || die "development files found in the package"
ok "no development files in the package"

# 4e. Every PHP file in the package must parse.
if command -v php >/dev/null 2>&1; then
	find "$PKG" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null \
		|| die "a PHP file in the package does not parse"
	ok "every PHP file parses"
else
	echo "  skip  php not on PATH — syntax of the staged files was not checked"
fi

# ---------------------------------------------------------------------
# 5. Package
# ---------------------------------------------------------------------
echo "== packaging"

mkdir -p "$BUILD_DIR"
ZIP="$BUILD_DIR/$SLUG-$VERSION.zip"

if [ -e "$ZIP" ]; then
	if [ "$FORCE" -eq 1 ]; then
		echo "  note  overwriting existing artefact: ${ZIP#"$ROOT"/}"
		rm -f "$ZIP"
	else
		die "$ZIP already exists. Bump the version, delete it, or pass --force."
	fi
fi

command -v zip >/dev/null 2>&1 || die "the 'zip' command is not available"

# Zipped from the staging root so the archive contains auto-csp-manager/… and
# nothing above it. -X drops extra file attributes, which keeps the archive
# closer to byte-identical between machines.
( cd "$STAGE" && zip -rqX "$ZIP" "$SLUG" )

[ -f "$ZIP" ] || die "the ZIP was not produced"

# ---------------------------------------------------------------------
# 6. Report
# ---------------------------------------------------------------------
COUNT="$(unzip -Z1 "$ZIP" | grep -vc '/$' || true)"
SIZE="$(wc -c < "$ZIP" | tr -d ' ')"

if command -v shasum >/dev/null 2>&1; then
	SHA="$(shasum -a 256 "$ZIP" | awk '{print $1}')"
elif command -v sha256sum >/dev/null 2>&1; then
	SHA="$(sha256sum "$ZIP" | awk '{print $1}')"
else
	SHA="(no sha256 tool available)"
fi

echo
echo "  artefact   ${ZIP#"$ROOT"/}"
echo "  version    $VERSION"
echo "  files      $COUNT"
echo "  size       $SIZE bytes"
echo "  sha256     $SHA"
echo "  built      $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
echo
echo "  Install with: Plugins > Add Plugin > Upload Plugin."
echo
