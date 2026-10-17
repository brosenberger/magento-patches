#!/usr/bin/env bash
# End-to-end test against a fake Magento project. Needs php, composer, git, zip, diff.
#
#   tests/e2e/run.sh                                   # basic run
#   QPT_PATH=~/src/quality-patches tests/e2e/run.sh    # also test patches:quality with real QPT data
#   WORK=/tmp/mp-e2e KEEP=1 tests/e2e/run.sh           # keep the fake shop for inspection
set -u
PLUGIN=$(cd "$(dirname "$0")/../.." && pwd)
WORK=${WORK:-$(mktemp -d)}
QPT=()
if [ -n "${QPT_PATH:-}" ]; then
  QPT=(--qpt-path="$QPT_PATH")
  export TRANSPORT_PHP=$WORK/Transport-2.4.8.php
  [ -f "$TRANSPORT_PHP" ] || curl -sSf -o "$TRANSPORT_PHP" https://raw.githubusercontent.com/magento/magento2/2.4.8/app/code/Magento/Email/Model/Transport.php
fi
bash "$PLUGIN/tests/e2e/fixtures.sh" "$WORK" "$PLUGIN"
IN=$WORK/patches-in
SHOP=$WORK/shop
cd "$SHOP" || exit 1
composer install -n -q || exit 1

pass=0; fail=0
check() { if eval "$2"; then echo "PASS $1"; pass=$((pass+1)); else echo "FAIL $1"; echo "$out" | tail -15 | sed 's/^/     /'; fail=$((fail+1)); fi; }
run() { composer "$@" -n 2>&1; }
count() { grep -c "$1" "$2"; }
DUMMY=vendor/magento/module-email/Model/Dummy.php
WIDGET=vendor/magento/module-email/view/frontend/web/js/widget.js

# --- quality patches -------------------------------------------------------------------------
if [ ${#QPT[@]} -gt 0 ]; then
  out=$(run patches:quality AC-14985 "${QPT[@]}"); check "qpt: file picked for installed version and applied" '[ $(count SMTP_SCHEME vendor/magento/module-email/Model/Transport.php) -eq 2 ]'
  out=$(run patches:quality AC-14985 "${QPT[@]}"); check "qpt: second run is a no-op" 'grep -q "Nothing to do" <<<"$out"'
fi

# --- security downloads ----------------------------------------------------------------------
out=$(run patches:security "$IN/APSB26-999.zip"); check "security: zip applied (package + root-deployed file)" 'grep -q strip_tags vendor/magento/module-catalog/Model/Product.php && grep -q aria lib/web/mage/menu.js'
check "security: download kept in patches/downloads/APSB26-999/" '[ -f patches/downloads/APSB26-999/APSB26-999.zip ]'
check "security: other-version file and not-installed modules left out" 'grep -q "module-staging not installed" <<<"$out" && ! grep -q "2.4.7-p10" <<<"$out"'
out=$(run patches:security "$IN/APSB26-999.zip"); check "security: second run is a no-op" 'grep -q "Nothing to do" <<<"$out" && [ $(ls patches/downloads/APSB26-999 | wc -l) -eq 1 ]'
out=$(run patches:security "$IN/APSB26-998_VULN-1002_2.4.8.patch"); check "security: same fix in another bulletin → covered, download kept" 'grep -q "recorded as covered" <<<"$out" && [ -f patches/downloads/APSB26-998/APSB26-998_VULN-1002_2.4.8.patch ]'

# --- migration from an old patch script; Adobe's monthly file naming -------------------------
MONTHLY=$IN/248-2026-07-001-CE.patch
patch -p1 -s < "$MONTHLY"   # what `post-install-cmd: patch -p1 … || true` used to do
out=$(run patches:security "$MONTHLY" --id=APSB26-900); check "migration: vendor/ patched by an old script → conflict, nothing registered" 'grep -q "patched outside composer-patches" <<<"$out" && grep -q "composer reinstall magento/module-email" <<<"$out" && ! grep -q APSB26-900 patches.json'
rm -f vendor/bin/patch-status && composer reinstall magento/module-email -n -q
printf "<?php return ['MAGE_MODE' => 'production'];\n" > app/etc/env.php
out=$(CI= run patches:security "$MONTHLY" --id=APSB26-900 --dry-run); check "dry run is allowed with MAGE_MODE=production" '! grep -q "MAGE_MODE is production" <<<"$out" && grep -q "Dry run" <<<"$out"'
rm app/etc/env.php
out=$(run patches:security "$MONTHLY" --id=APSB26-900); check "security: compact file name (248-2026-07-001-CE) picked, patch-status left out" 'grep -q "return 100;" $DUMMY && grep -q "patch-status tool" <<<"$out" && [ ! -e vendor/bin/patch-status ]'
out=$(run patches:remove APSB26-900); check "remove: monthly file taken out again" '! grep -q "return 100;" $DUMMY'

# --- re-application safety: constants, methods, JS functions -----------------------------------
out=$(run patches:add "$IN/const-x.patch"); check "custom: add-only constant" '[ $(count "const X" $DUMMY) -eq 1 ]'
out=$(run patches:add "$IN/const-x.patch" --name=copy); check "custom: same change again → already applied, not duplicated" 'grep -q "already applied" <<<"$out" && [ $(count "const X" $DUMMY) -eq 1 ]'
out=$(run patches:add "$IN/const-x-elsewhere.patch"); check "custom: same constant at another place → skipped with warning" 'grep -q "declares only what already exists" <<<"$out" && [ $(count "const X" $DUMMY) -eq 1 ]'
out=$(run patches:add "$IN/const-x-elsewhere.patch" --strict); check "custom: --strict turns that into a conflict" 'grep -q CONFLICT <<<"$out"'
out=$(run patches:add "$IN/const-xy.patch"); check "custom: partially present declarations → conflict" 'grep -q "1 of 2" <<<"$out"'
out=$(run patches:add "$IN/method-d.patch"); check "custom: add a PHP method" '[ $(count "function d()" $DUMMY) -eq 1 ]'
out=$(run patches:add "$IN/method-d.patch" --name=method-again); check "custom: same method again → already applied" 'grep -q "already applied" <<<"$out" && [ $(count "function d()" $DUMMY) -eq 1 ]'
out=$(run patches:add "$IN/method-d-elsewhere.patch"); check "custom: same method at another place → skipped (d())" 'grep -q "d()" <<<"$out" && [ $(count "function d()" $DUMMY) -eq 1 ]'
out=$(run patches:add "$IN/js-function.patch"); check "custom: add a JS function" '[ $(count "function toggleAll" $WIDGET) -eq 1 ]'
out=$(run patches:add "$IN/js-function-elsewhere.patch"); check "custom: same JS function elsewhere → skipped (node --check would not notice)" 'grep -q "declares only what already exists" <<<"$out" && [ $(count "function toggleAll" $WIDGET) -eq 1 ]'
out=$(run patches:add "$IN/ambiguous.patch"); check "custom: offset with ambiguous context → conflict" 'grep -q ambiguous <<<"$out"'
out=$(run patches:add "$IN/broken.patch"); check "custom: lint regression → conflict" 'grep -q "fails lint" <<<"$out"'
out=$(run patches:add "$IN/unsupported.patch"); check "custom: file no package provides → conflict" 'grep -q "no installed package provides" <<<"$out"'
out=$(run patches:add "$IN/catalog-helper.patch"); check "custom: new file + xml change" '[ -f vendor/magento/module-catalog/Model/Helper.php ]'
out=$(run patches:status); check "status: everything applied" '! grep -q "not applied" <<<"$out"'
# --- composer-patches 1.x style patch at depth 4 ("a/vendor/<vendor>/<pkg>/…") next to managed ones ---
sed 's/return 2;/return 22;/' $DUMMY > "$WORK/Dummy.depth4.php"
mkdir -p legacy && { echo "diff --git a/$DUMMY b/$DUMMY"; diff -u --label "a/$DUMMY" --label "b/$DUMMY" $DUMMY "$WORK/Dummy.depth4.php"; } > legacy/depth4.patch
php -r '$f = "patches.json"; $d = json_decode(file_get_contents($f), true); $d["patches"]["magento/module-email"]["legacy depth-4 fix"] = "legacy/depth4.patch"; file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
patch -p1 -s < legacy/depth4.patch   # applied by composer-patches 1.x long ago
composer config --json extra.composer-patches.package-depths '{"magento/module-email": 4}'
php -r 'file_put_contents($argv[2], str_replace("    public function a()", "    private const Z = 26;\n\n    public function a()", file_get_contents($argv[1])));' $DUMMY "$WORK/Dummy.constZ.php"
{ echo "diff --git a/$DUMMY b/$DUMMY"; diff -u --label "a/$DUMMY" --label "b/$DUMMY" $DUMMY "$WORK/Dummy.constZ.php"; } > "$WORK/const-z.patch"
out=$(run patches:add "$WORK/const-z.patch"); check "depth: managed patch added to a package-depths=4 package is pinned to depth 1, legacy one applied at 4" 'grep -q "const Z" $DUMMY && grep -q "return 22;" $DUMMY && grep -q "\"depth\": 1" patches.json'
out=$(run patches:status); code=$?; check "status: depth-4 entry verified at its configured depth" '[ $code -eq 0 ] && ! grep -q "not applied" <<<"$out"'
php -r '$f = "patches.json"; $d = json_decode(file_get_contents($f), true); $p = &$d["patches"]["magento/module-email"]; if (isset($p[0])) { $p = array_values(array_filter($p, fn ($e) => ($e["description"] ?? "") !== "legacy depth-4 fix")); } else { unset($p["legacy depth-4 fix"]); } file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
composer config --unset extra.composer-patches && rm -rf legacy && run patches:remove const-z >/dev/null
composer config scripts.post-install-cmd 'patch -p1 -N < composer-patches/x.patch || true' && composer config extra.patches-file composer.patches.json
out=$(run patches:status); code=$?; check "status: old patch script and the 1.x patches-file key are reported" '[ $code -eq 1 ] && grep -q "runs \`patch\`" <<<"$out" && grep -q "2.x ignores it" <<<"$out"'
composer config --unset scripts.post-install-cmd && composer config --unset extra.patches-file

# --- stored bulletin data ----------------------------------------------------------------------
rm -f patches/security-bulletins.json
out=$(run patches:security --offline); code=$?; check "bulletins: no stored data offline → error, not a false all-clear" '[ $code -eq 1 ] && grep -q "No bulletin data" <<<"$out"'
php -r '
require "vendor/autoload.php";
$b = (new BroCode\MagentoPatches\Source\BulletinParser())->parse("APSB26-146", "https://helpx.adobe.com/security/products/magento/apsb26-146.html", file_get_contents($argv[1]));
$s = new BroCode\MagentoPatches\Source\BulletinStore("patches/security-bulletins.json");
$s->setIndex(["APSB26-146"]); $s->put($b); $s->save();' "$PLUGIN/tests/unit/apsb-sample.html"
out=$(run patches:security); code=$?
check "bulletins: fresh stored index + stored bulletin → nothing fetched" '! grep -qi "could not fetch" <<<"$out" && grep -q "1 stored" <<<"$out"'
check "bulletins: monthly bound vs. installed release date → missing (exit 2)" '[ $code -eq 2 ] && grep -q EXPLOITED <<<"$out" && grep -q ka-49999 <<<"$out"'
php -r 'require "vendor/autoload.php"; $s = new BroCode\MagentoPatches\Source\BulletinStore("patches/security-bulletins.json"); $s->setIndex(["APSB26-146", "APSB26-147"]); $s->save();'
out=$(run patches:security --offline); check "bulletins: a bulletin that cannot be read is reported as unknown" 'grep -q "Could not check APSB26-147" <<<"$out"'
php -r 'require "vendor/autoload.php"; $s = new BroCode\MagentoPatches\Source\BulletinStore("patches/security-bulletins.json"); $s->setIndex(["APSB26-146"]); $s->save();'
out=$(run patches:security --offline --show=APSB26-146); check "bulletins: --show from stored data" 'grep -q "affected — MISSING" <<<"$out" && grep -q "2.4.8-2026-aug and earlier" <<<"$out"'

# --- downloads tracked against Adobe (fake KB + repo.magento.com over HTTP) --------------------------
PORT=$((20000 + RANDOM % 20000)); BASE=http://127.0.0.1:$PORT
php -S 127.0.0.1:$PORT -t "$WORK/www" >/dev/null 2>&1 & SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
sleep 1
source "$PLUGIN/tests/e2e/www.sh" "$WORK/www" "$BASE"
kb1 2026-10-13; bulletin995
php -r '
require "vendor/autoload.php";
$b = (new BroCode\MagentoPatches\Source\BulletinParser())->parse("APSB26-995", $argv[2], file_get_contents($argv[1]));
$s = new BroCode\MagentoPatches\Source\BulletinStore("patches/security-bulletins.json");
$s->put($b); $s->save();' "$WORK/www/security/apsb26-995.html" "$BASE/security/apsb26-995.html"
MAILER=vendor/magento/module-email/Model/Mailer.php
MANIFEST=patches/downloads/manifest.json
sha() { php -r 'echo hash_file("sha256", $argv[1]);' "$1"; }
out=$(run patches:security --download=APSB26-995); check "download: the file for the installed version is fetched from the KB article" 'grep -q "return 30;" $MAILER && [ -f patches/downloads/APSB26-995/2-4-8-oct-2026.zip ] && [ ! -f patches/downloads/APSB26-995/2-4-7-p10-oct-2026.zip ]'
check "download: sha256 + source URL recorded in the manifest" 'grep -q "\"source\": \"$BASE/patch/2-4-8-oct-2026.zip\"" $MANIFEST && grep -q "$(sha $WORK/www/v/v1.zip)" $MANIFEST'
out=$(run patches:security --check-downloads); check "check-downloads: unchanged file → nothing happens" 'grep -q "unchanged on the server" <<<"$out" && ! grep -q "Removing magento" <<<"$out"'
cp "$WORK/www/v/v2.zip" "$WORK/www/patch/2-4-8-oct-2026.zip"
out=$(run patches:security --check-downloads); check "check-downloads: file replaced on the server → re-downloaded, bad change gone from vendor" 'grep -q "replaced on the server" <<<"$out" && ! grep -q "return 30;" $MAILER && grep -q "strip_tags" $MAILER && grep -q "$(sha $WORK/www/v/v2.zip)" $MANIFEST'
check "check-downloads: other patches of the package rebuilt in order" '[ $(count "function d()" $DUMMY) -eq 1 ] && [ $(count "function toggleAll" $WIDGET) -eq 1 ]'
out=$(run patches:status); check "check-downloads: status clean afterwards" '! grep -q "not applied" <<<"$out" && ! grep -q "✗" <<<"$out"'
cp "$WORK/www/v/v2-repackaged.zip" "$WORK/www/patch/2-4-8-oct-2026.zip"
out=$(run patches:security --check-downloads); check "check-downloads: repackaged with same patch → stored file updated, no reinstall" 'grep -q "repackaged" <<<"$out" && ! grep -q "Removing magento" <<<"$out" && grep -q "$(sha $WORK/www/v/v2-repackaged.zip)" $MANIFEST'
kb1 2026-10-16 "<p>Note: <a href=\"$BASE/kbarticles/ka-2.html\">Updated October 2026 Isolated security patch for 2.4.8</a></p>"; kb2
cp "$WORK/www/v/v3.zip" "$WORK/www/patch/2-4-8-oct-2026-v2.zip"
out=$(run patches:security --max-age=0); check "daily check: changed KB article is reported" 'grep -q "KB article changed" <<<"$out" && grep -q "check-downloads" <<<"$out"'
out=$(run patches:security --check-downloads); check "check-downloads: updated-patch article supersedes the old file" 'grep -q "superseded" <<<"$out" && grep -q "return 5;" $MAILER && [ -f patches/downloads/APSB26-995/2-4-8-oct-2026-v2.zip ] && [ ! -f patches/downloads/APSB26-995/2-4-8-oct-2026.zip ]'
cp "$WORK/www/v/v1.zip" patches/downloads/APSB26-995/2-4-8-oct-2026-v2.zip
out=$(run patches:sync --check); code=$?; check "sync --check: download changed in the repo → fails" '[ $code -eq 1 ] && grep -q "differs from what was registered" <<<"$out"'
out=$(run patches:status); check "status: changed download reported" 'grep -q "content differs from manifest" <<<"$out"'
out=$(run patches:sync); check "sync: changed download re-validated and re-applied" 'grep -q "return 30;" $MAILER && grep -q "$(sha $WORK/www/v/v1.zip)" $MANIFEST'
out=$(run patches:sync --check); code=$?; check "sync --check: clean afterwards" '[ $code -eq 0 ] && grep -q "All stored downloads" <<<"$out"'
cp "$WORK/www/v/v1.zip" "$WORK/v1-copy.zip"; cp "$WORK/www/v/v3.zip" "$WORK/2-4-8-oct-2026-v2.zip"
out=$(run patches:security "$WORK/2-4-8-oct-2026-v2.zip" --id=APSB26-995); check "security <file>: same name, other content → treated as new version" 'grep -q "different content" <<<"$out" && grep -q "return 5;" $MAILER'
cp "$WORK/www/v/v4.zip" "$WORK/www/patch/2-4-8-oct-2026-v2.zip"
before=$(sha1sum patches.json patches.lock.json $MANIFEST patches/downloads/APSB26-995/* $MAILER)
out=$(run patches:security --check-downloads); code=$?
check "check-downloads: replacement that does not fit → rolled back, nothing changed" '[ $code -ne 0 ] && grep -q "Rolling back" <<<"$out" && [ "$before" = "$(sha1sum patches.json patches.lock.json $MANIFEST patches/downloads/APSB26-995/* $MAILER)" ]'
kill $SERVER 2>/dev/null

# --- rollback, remove, guard -----------------------------------------------------------------
before=$(sha1sum patches.json patches.lock.json)
sed -i.bak 's/return \$this->name;/return trim($this->name);/' vendor/magento/module-catalog/Model/Product.php && rm -f vendor/magento/module-catalog/Model/Product.php.bak
out=$(run patches:add "$IN/handedit.patch"); check "rollback: failure after writing restores everything" '[ "$before" = "$(sha1sum patches.json patches.lock.json)" ] && grep -q "Rolled back" <<<"$out" && [ ! -f patches/magento/module-catalog/custom/handedit.patch ]'
out=$(run patches:remove const-x); check "remove: entry gone, vendor reinstalled without it" '[ $(count "const X" $DUMMY) -eq 0 ]'
out=$(run patches:remove APSB26-998); check "remove: covered bulletin + its download removed" '[ ! -d patches/downloads/APSB26-998 ] && ! grep -q APSB26-998 patches.json'
printf "<?php return ['MAGE_MODE' => 'production'];\n" > app/etc/env.php
out=$(CI= run patches:add "$IN/const-x.patch"); check "guard: refuses on MAGE_MODE=production" 'grep -q "MAGE_MODE is production" <<<"$out"'
rm app/etc/env.php

# --- CI: a download that was only committed ------------------------------------------------------
cp "$IN/APSB26-997_VULN-1003_2.4.8.patch" patches/downloads/
out=$(run patches:sync --check); code=$?
check "ci: sync --check fails for a committed but unregistered download" '[ $code -eq 1 ] && grep -q APSB26-997 <<<"$out"'
CI_DIR=$WORK/ci-checkout
rm -rf "$CI_DIR" && mkdir -p "$CI_DIR" && tar --exclude=./vendor -cf - . | (cd "$CI_DIR" && tar -xf -)
pushd "$CI_DIR" >/dev/null
composer install -n -q 2>/dev/null
out=$(CI=true run patches:sync); check "ci: sync in a fresh checkout registers and applies it" 'grep -q "return (int) 1;" $DUMMY && grep -q "APSB26-997" patches.json'
out=$(run patches:sync --check); check "ci: sync --check passes afterwards" 'grep -q "All stored downloads are registered" <<<"$out"'
out=$(run patches:status); check "ci: everything applied in the build" '! grep -q "not applied" <<<"$out"'
popd >/dev/null

# --- upgrade and deploy ------------------------------------------------------------------------
out=$(run patches:upgrade 2.4.8-p1 "${QPT[@]}"); check "upgrade: fix contained in the release dropped, rest kept" 'grep -q "contained in the installed code now" <<<"$out" && grep -q "Upgraded to 2.4.8-p1" <<<"$out" && grep -q aria lib/web/mage/menu.js'
rm -rf vendor && out=$(composer install -n --no-dev 2>&1); check "deploy: fresh install --no-dev applies the lock" 'grep -q aria vendor/magento/magento2-base/lib/web/mage/menu.js && [ -f vendor/magento/module-catalog/Model/Helper.php ]'

echo "---- $pass passed, $fail failed  (work dir: $WORK)"
[ -n "${KEEP:-}" ] || rm -rf "$WORK"
[ "$fail" -eq 0 ]
