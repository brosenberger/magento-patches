# brocode/magento-patches

Composer commands for Magento 2 / Adobe Commerce patches: monthly security fixes and hotfixes,
Quality Patches (QPT) and your own patches — split per package, matched to the **installed**
versions, validated before anything is written, idempotent, and rolled back completely if
anything goes wrong.

Applying is done by [cweagans/composer-patches](https://github.com/cweagans/composer-patches) 2.x,
so a deploy is plain `composer install`. No `scripts` entries, no extra download tool.

```bash
composer require brocode/magento-patches   # Composer asks once to allow the two plugins
```

## Commands

| Command | What it does |
|---|---|
| `composer patches:security` | Lists Adobe bulletins that affect the installed version, oldest first, with download link and file name. Exit code 2 if something is missing. |
| `composer patches:security <zip\|patch\|dir\|url>` | Keeps the download in `patches/downloads/<ID>/` (sha256 + source in `manifest.json`), picks the file for your version, splits it per package, validates, registers. |
| `composer patches:security --download=APSB26-153` | Fetches the file for your version from the bulletin's KB article (repo.magento.com, keys from `auth.json`) and adds it. |
| `composer patches:security --check-downloads` | Re-downloads every stored file, compares sha256, and replaces what Adobe changed or superseded (also part of `--refresh`). |
| `composer patches:sync` | Brings `patches.json` in line with `patches/downloads/`: registers new downloads, rebuilds bulletins whose files changed in the repo — for CI. `--check` only reports (exit 1). |
| `composer patches:quality ACSD-12345 …` | Adds QPT patches; the file matching the installed versions is chosen, `require` / `replaced-with` are followed. |
| `composer patches:quality --resync` | Re-resolves all registered QPT patches (after updating `magento/quality-patches`). |
| `composer patches:add <file> [--name=…] [--package=…]` | Adds your own patch the same way. |
| `composer patches:remove <id> …` | Removes entries by id (APSB26-153, ACSD-12345, your name); for bulletins also the stored download. |
| `composer patches:upgrade 2.4.7-p11` | Upgrades Magento and carries patches over: what the release already contains is dropped, the rest is re-validated. |
| `composer patches:status` | Installed version, latest `-p` of your line, a check that every entry in `patches.json` is really applied in `vendor/`, and old patch mechanisms still in `composer.json`. |

Mutating commands accept `--dry-run`, `--strict`, `--skip-unsupported`, and refuse to run when
`MAGE_MODE=production` (`--force` overrides; `CI=true` counts as a build, not a live shop). A
`--dry-run` changes nothing and is allowed in production mode.

Coming from patch scripts in `composer.json`, composer-patches 1.x, the QPT tool or hand-split
files: see [MIGRATION.md](MIGRATION.md).

## Monthly security workflow

```bash
composer patches:security                        # what is missing for this install?
#  APSB26-153  October 13, 2026, priority 2 — affects Magento Open Source core ("2.4.7-p10 and earlier")
#    download: https://experienceleague.adobe.com/…/ka-49323
#    file:     the one for 2.4.7-p10 / 2.4.7-2026-oct (isolated patch)
#    then:     composer patches:security <download> --id=APSB26-153
composer patches:security ~/Downloads/APSB26-153.zip
git add patches/ patches.json patches.lock.json && git commit -m "APSB26-153"
```

Running the same command twice changes nothing. A bulletin whose files need no entry (already
contained, or only for modules you don't have) is recorded as covered, so the check stops listing it.

## Stored in the project

```
patches.json                         # read by composer-patches; managed entries are labelled
patches.lock.json                    # what `composer install` applies
patches/downloads/APSB26-153/…zip    # the download, exactly as received from Adobe
patches/downloads/manifest.json      # sha256, size, source URL of each download + KB article snapshots
patches/security-bulletins.json      # parsed bulletin data (lookup + offline checks)
patches/<vendor>/<package>/sec/…     # [SEC] split per package
patches/<vendor>/<package>/qpt/…     # [QPT]
patches/<vendor>/<package>/custom/…  # [CUSTOM]
```

Commit all of it. Entries in `patches.json` without a `[SEC]`/`[QPT]`/`[CUSTOM]` label are never touched.
The two paths under `patches/` can be changed in `composer.json`:
`"extra": {"brocode-magento-patches": {"downloads-dir": "…", "bulletins-file": "…"}}`.

### Downloads and CI

Every download given to `patches:security` is kept in `patches/downloads/<ID>/` in the same
transaction as the registration. A download can also just be committed there — as
`patches/downloads/APSB26-153/<file>` or `patches/downloads/APSB26-153_anything.zip` — and
`patches:sync` registers it:

```yaml
# e.g. GitLab CI / GitHub Actions step
- composer install --no-interaction
- composer patches:sync --no-interaction           # registers + applies committed downloads
# or, as a gate that a developer ran it locally:
- composer patches:sync --check                    # exit 1: unregistered download or stale lock
- composer patches:security --offline              # exit 2: committed bulletin data shows a missing fix
```

### Replaced downloads

Adobe sometimes fixes a broken isolated patch by replacing the file on the server, under the same
name or through an "Updated … isolated security patch" KB article. Every stored download therefore
has its sha256 and source URL in `patches/downloads/manifest.json`, and changes are picked up in
three ways:

| Where the change happens | Noticed by | What happens |
|---|---|---|
| Adobe replaces the file behind the same URL | `--check-downloads` / `--refresh` re-downloads and compares sha256 | the new file is stored and applied |
| Adobe publishes an "updated patch" article | the daily check sees the KB article's *Last update* change and reports it; `--check-downloads` acts | the file from the updated article supersedes the old one |
| someone commits a new version into `patches/downloads/` | `patches:sync` / `patches:status` compare with the manifest | `sync` applies it; `sync --check` fails the build |
| `patches:security <file>` with a stored name but other content | name + hash | treated as a new version (`--replace` swaps the whole set) |

Applying a new version works like every other change. The new file's hunks are compared with
what is registered:
- **Same hunks** (only repackaged): just the stored file and manifest are updated.
- **Different hunks**: every managed patch of the affected packages is taken out, re-validated in
  its original order with the new file in place of the old, and applied again. A change Adobe
  removed is therefore gone from `vendor/` too.
- **New file doesn't fit**: everything is rolled back, and the old download stays in place.

A download added by hand has no source URL. The first `--check-downloads` that finds the same bytes
on Adobe's server records the URL; if it finds a different file for your version, that file
supersedes the manual one.

`patches:upgrade` also uses the stored downloads: if one contains a file for the new version, that
file is used instead of the old split patches.

### Bulletin data

`patches/security-bulletins.json` holds every bulletin parsed so far — affected versions, fixed
versions, CVEs, download links — readable without tooling and usable offline:

- The **index** page is re-checked at most every 24 hours (`--max-age=<hours>`).
- Each **bulletin** is fetched once and then read from the file. `--refresh` re-reads the index and
  all bulletins in range, because Adobe sometimes revises them.
- `--offline` fetches nothing. That's the mode for CI, and it's automatic when `MAGE_MODE=production`.
- `composer patches:security --show=APSB26-146` prints one bulletin and what it means for the installed version.

## How the installed version is determined

Everything comes from Composer's local repository (`vendor/composer/installed.json`), i.e. what is
really installed, not what `composer.json` asks for:

- **Core version and edition** come from `magento/magento2-base` (`magento2-ee-base` → Commerce),
  along with its **release date**. Adobe's monthly bounds like `2.4.7-2026-aug and earlier` are
  not Composer versions, so the release date decides them.
- **B2B** comes from `magento/extension-b2b` / `magento2-b2b-base`.
- **QPT constraints** (`>=2.4.7 <2.4.7-p3`) are matched with Composer's own semver against the
  anchor package QPT names.
- **Isolated patch files** are picked by the installed version in the file name — dotted
  (`VULN-39341_2.4.8-p5.patch`, `2-4-8-p5-oct-2026.zip`) or compact as in Adobe's monthly files
  (`248p5-2026-07-001-CE.patch`, `VULN-39341_248-p5.patch`, B2B `133p18-2026-07-001-B2B.patch`).
  Adobe's monthly-build files for the line are used only if no exact file exists, and you get a
  warning when you're not on the latest `-p`.
- Changes to `vendor/bin/patch-status` (Adobe's versioning tool, shipped inside the monthly files)
  are left out with a note: no package owns `vendor/bin`, and the tool fixes nothing —
  `patches:status` covers the same ground.

## Safety model

Every file change of a patch is checked against the state it will meet, in order:

1. **Reverse check.** If the exact change is already there, it's skipped — but only when
   `composer install` will produce that state again: a registered patch touches the file, or the
   **pristine package** (downloaded through Composer, normally from its cache) already contains the
   change. Otherwise `vendor/` was patched behind composer-patches (an old script, composer-patches
   1.x, a hand edit); skipping would lose the fix on the next install, so it is a conflict that names
   the packages to reinstall.
2. **Declarations.** If everything the change declares already exists in the file, it's skipped
   with a warning (`--strict`: conflict). If only part of it exists, it's a conflict. This catches
   an add-only hunk that would otherwise "apply" a second time somewhere else.
   - PHP: `const`, function/method, property, `use`, class, enum case.
   - JavaScript: function declarations and expressions, object/class methods. A duplicated JS
     function is legal JavaScript, so no linter would notice it.
3. **Forward check** with `git apply`, the same patcher composer-patches uses. A hunk landing at an
   offset is only accepted if its context is unique in the file.
4. **Apply + lint** on a scratch copy: `php -l` (also catches a duplicated PHP method), `xmllint`,
   `node --check`, JSON. Only a regression counts.

If any change conflicts, nothing is written. Otherwise the plugin writes the patch files, the
download and `patches.json`, runs `composer patches-relock`, then `composer reinstall` for the
affected packages, and finally checks that `vendor/` is **byte-identical** to the validated result.
If any step fails, `patches.json`, `patches.lock.json`, the patch files and downloads (and for
upgrades `composer.json`/`composer.lock`) are restored and the packages are reinstalled, putting
you back where you started.

Patches touching root files that Magento deploys from a package (`lib/web`, `setup/src`,
`app/etc/di.xml` via `magento2-base`'s `map`) are attached to that package. Unmodified root copies
are kept in sync.

## Requirements and notes

- PHP 8.1+, Composer 2.3+, `git` (composer-patches needs it too), ext-zip, ext-dom.
- `magento/quality-patches` is only needed for `patches:quality` (or pass `--qpt-path` to a clone of
  github.com/magento/quality-patches).
- Bulletin data comes from Adobe's public pages on helpx.adobe.com, because there is no API. If the
  layout changes you get an error instead of a false "nothing missing"; `--bulletin=APSB26-153`
  checks specific bulletins.
- Downloads behind Adobe login (Commerce ≤ 2.4.6 needs Composer keys) stay a manual step; the
  command only suggests the link.
- A hand edit in `vendor/` that makes a patch *fit* (rather than already contain it) is not seen
  before validation; the reinstall then fails and the run rolls back.
- To change a custom patch, `patches:remove` it and add it again; the same name counts as already
  registered.

## Tests

```bash
php tests/unit/run.php
tests/e2e/run.sh                                      # fake Magento project, a few minutes
QPT_PATH=/path/to/quality-patches tests/e2e/run.sh    # incl. a real QPT patch
```
