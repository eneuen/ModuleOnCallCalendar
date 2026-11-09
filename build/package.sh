#!/bin/sh
# Builds the install zip for a release from a commit (HEAD by default):
#   build/package.sh 1.0.0 [commit]
# Writes dist/ModuleOnCallCalendar-<version>.zip and its SHA-256 checksum.
#
# The zip holds the module files at its root, as MikoPBX expects when a
# module is uploaded on the Modules page. Only committed files are packaged,
# so local changes and ignored files never end up in a release. Development
# files (tests, tools, build scripts, browser-script sources, dependency
# manifests, CI configuration) are marked export-ignore in .gitattributes,
# the one list of what doesn't ship; git archive leaves them out.
set -eu

VERSION=${1:-}
COMMIT=${2:-HEAD}
ID=ModuleOnCallCalendar
if ! printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'; then
    echo "Usage: $0 <version> [commit]   (version like 1.0.0 or 1.0.0-rc.1)" >&2
    exit 2
fi
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
git rev-parse --verify --quiet "$COMMIT^{commit}" >/dev/null || { echo "Unknown commit: $COMMIT" >&2; exit 2; }

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir "$WORK/$ID"
git archive --format=tar "$COMMIT" | tar -xf - -C "$WORK/$ID"

cd "$WORK/$ID"
# Guard against a commit whose .gitattributes is missing or incomplete.
for p in tests tools build .github CONTRIBUTING.md public/assets/js/src composer.json package.json phpunit.xml.dist .gitattributes; do
    [ ! -e "$p" ] || { echo "Development file in the package: $p (check export-ignore in .gitattributes)" >&2; exit 1; }
done
grep -q '"version": "%ModuleVersion%"' module.json || { echo "module.json has no %ModuleVersion% placeholder" >&2; exit 1; }
sed "s/%ModuleVersion%/$VERSION/" module.json > module.json.new && mv module.json.new module.json
php -r 'json_decode(file_get_contents("module.json"), false, 4, JSON_THROW_ON_ERROR);'

# Sanity checks on what is about to ship.
for f in module.json LICENSE Lib/OnCallCalendarConf.php Setup/PbxExtensionSetup.php \
         public/assets/js/on-call-calendar.js public/assets/js/vendor/fullcalendar.min.js \
         public/assets/css/on-call-calendar.css public/assets/css/vendor/fullcalendar.min.css; do
    [ -f "$f" ] || { echo "Missing from the package: $f" >&2; exit 1; }
done
if find . -name '*.db' -o -name '.DS_Store' -o -name '._*' | grep -q .; then
    echo "Unexpected files in the package:" >&2
    find . -name '*.db' -o -name '.DS_Store' -o -name '._*' >&2
    exit 1
fi

mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/$ID-$VERSION.zip"
rm -f "$OUT"
# Sorted names and no extra file attributes, so the same commit gives the same zip.
find . -type f | LC_ALL=C sort | sed 's#^\./##' | zip -q -X -D "$OUT" -@
cd "$ROOT/dist"
if command -v sha256sum >/dev/null; then sha256sum "$ID-$VERSION.zip"; else shasum -a 256 "$ID-$VERSION.zip"; fi > "$ID-$VERSION.zip.sha256"
echo "Built dist/$ID-$VERSION.zip ($(git -C "$ROOT" rev-parse --short "$COMMIT"), $(wc -c < "$OUT" | tr -d ' ') bytes)"
cat "$ID-$VERSION.zip.sha256"
