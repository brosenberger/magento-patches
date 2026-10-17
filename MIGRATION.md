# Migrating to brocode/magento-patches

How to move a Magento project from an older patch setup to this plugin without losing a fix on the
way. Every section was checked against real projects (Magento 2.4.4-p18 to 2.4.9, Open Source and
Commerce/B2B) that used these mechanisms.

## The one rule

**Register patches against a pristine `vendor/`.** Old mechanisms changed `vendor/` directly. A
change that is already there is skipped by the plugin, and the next `composer install` (CI, deploy,
a colleague) brings back the unpatched file. Since this release the plugin refuses that case:

```
CONFLICT APSB26-73 (248p5-2026-07-001-CE.patch) → magento/module-quote:etc/di.xml — in vendor/, but not in the released package or a registered patch
CONFLICT vendor/ was patched outside composer-patches (old patch script, cweagans 1.x, hand edit) — the next `composer install` would undo it.
  Remove the old mechanism, then: composer reinstall magento/module-quote …
```

So the order is always: **switch the tooling → remove the old mechanism → pristine `vendor/` →
register → verify.**

## Which setup do you have?

`composer patches:status` lists what it finds in `composer.json` under *Old patch mechanisms*.
By hand:

| You see in `composer.json` | Setup | Section |
|---|---|---|
| `"patch -p1 -N … < composer-patches/248p5-2026-07-001-CE.patch \|\| true"` in `scripts` | Adobe isolated files applied by script | [A](#a-adobe-isolated-files-applied-by-a-script) |
| `"git apply patches/….patch --reject \|\| true"` in `scripts` | same, with `git apply` | [A](#a-adobe-isolated-files-applied-by-a-script) + [B](#b-git-apply---reject) |
| `"vendor/bin/magento-patches apply ACSD-… MDVA-…"` in `scripts` | Quality Patches Tool at install time | [C](#c-quality-patches-tool-in-scripts) |
| `"cweagans/composer-patches": "^1.7"` and `"extra": {"patches-file": "composer.patches.json"}` | composer-patches 1.x with a patches file | [D](#d-composer-patches-1x) |
| `"extra": {"patches": {…}}` | composer-patches inline patches | [D](#d-composer-patches-1x) + [E](#e-inline-extrapatches) |
| `"extra": {"composer-patches": {"patches-file": …}}` and cweagans `^2` | composer-patches 2.x already | [F](#f-composer-patches-2x-already) |
| folders like `composer-patches/APSB26-73/244p18-…-CE.patch` | bulletin folders | [A](#a-adobe-isolated-files-applied-by-a-script), shortcut in step 4 |
| `composer-patches/246p15-2026-07-001-CE/magento-framework.patch` … | hand-split cumulative file | [G](#g-hand-split-cumulative-files) |

Most projects combine several (typically D for custom fixes + A for security files). Do all
sections that apply, in one branch, in the order of the steps below.

## Steps (all setups)

### 1. Switch the tooling

The plugin needs composer-patches 2.x. With 1.x locked, require both together:

```bash
composer require cweagans/composer-patches:^2.0 brocode/magento-patches -W
```

Composer asks to allow both plugins (`config.allow-plugins`). In a DDEV project run every command
as `ddev composer …`.

### 2. Fix the composer-patches configuration *before* the next install

composer-patches 2.x **silently ignores** the 1.x key `extra.patches-file` — every patch in that
file stops being applied, without an error. Move it:

```json
"extra": {
    "composer-patches": {
        "patches-file": "composer.patches.json"
    }
}
```

(or rename the file to `patches.json`, the 2.x default, and drop the setting). The plugin reads the
same setting, so it adds its entries to that file.

Also remove `extra.composer-exit-on-patch-failure`: 2.x always fails the install when a patch does
not apply.

### 3. Remove the old mechanism

Delete the `scripts` entries that run `patch`, `git apply`, `magento-patches apply` or
`ece-patches apply`, and their references in `post-install-cmd` / `post-update-cmd`. Keep the patch
files for now — they are the input for step 5.

After a `git apply --reject`, look for leftovers: `find vendor app lib pub setup -name '*.rej'`.

### 4. Pristine `vendor/`

```bash
rm -rf vendor && composer install
```

This applies what composer-patches 2.x now reads (step 2) and nothing else. Expect the first
failures here — see [D](#d-composer-patches-1x) for patches 1.x applied and 2.x rejects.

Root copies deployed from `magento/magento2-base` (`lib/web`, `pub/errors`, `nginx.conf.sample`,
`app/etc/di.xml`, …) that an old script patched in place are rewritten by the reinstall when
`"magento-force": "override"` is set. Otherwise check them with `git status`.

### 5. Register

Oldest first, one bulletin per call:

```bash
composer patches:security                                                       # what's missing, with ids and file names
composer patches:security composer-patches/248p5-2026-07-001-CE.patch --id=APSB26-73
composer patches:security composer-patches/248p5-2026-08-001-CE.patch --id=APSB26-92
composer patches:quality ACSD-48570 MDVA-30106 …                                 # section C
composer patches:add patches/my-fix.patch --name=PROJ-123                       # custom fixes, optional (section D)
```

Shortcut for files already sorted by bulletin: move them to `patches/downloads/<ID>/`
(`composer-patches/APSB26-73/…` → `patches/downloads/APSB26-73/…`) and run
`composer patches:sync`.

`--dry-run` shows the result first and works even with `MAGE_MODE=production`.

### 6. Verify and commit

```bash
composer patches:status        # exit 0: every entry applied, no old mechanism left, lock current
git add composer.json composer.lock patches.json patches.lock.json patches/   # + your patches file
```

Then delete the old patch files that are now stored under `patches/` (the originals are kept in
`patches/downloads/`).

---

## A. Adobe isolated files applied by a script

```json
"apply-security-patches": [
    "patch -p1 -N --no-backup-if-mismatch -r /dev/null < composer-patches/248p5-2026-07-001-CE.patch || true"
]
```

What changes:
- `|| true` hid every failure. In the projects checked, a monthly file was **not applied at all** in
  the local `vendor/` while the script "succeeded", and a VULN file had one root file
  (`pub/errors/processor.php`) silently left out. The plugin validates before writing and
  `patches:status` keeps checking afterwards.
- The plugin splits each file per package (`patches/magento/module-quote/sec/…`) so composer-patches
  applies it on every install; root files Magento deploys from `magento2-base` are attached to that
  package.
- The monthly files contain `vendor/bin/patch-status`, Adobe's versioning tool. It belongs to no
  package and fixes nothing; the plugin leaves it out with a note. `vendor/bin/patch-status` will
  no longer exist after a fresh install — use `composer patches:status`.
- Monthly files patch the same files repeatedly (the October file changes lines July touched).
  Register them **in order**; the plugin validates each against the state the earlier ones leave.
- Files for another version than the installed one are rejected (e.g. a `…_245-p17.patch` on a
  2.4.5-p14 install). Get the file for your version, or upgrade first (`patches:upgrade`).

## B. `git apply --reject`

As A, plus: `--reject` applies what fits and writes `*.rej` for the rest, so `vendor/` can be
half-patched. Delete the `.rej` files; step 4 restores the rest. `--exclude="vendor/…"` arguments
in the old script mean parts of the file were deliberately skipped — the plugin applies everything
that belongs to an installed package, so check whether the exclusion is still needed (use
`patches:add` with a trimmed file instead).

## C. Quality Patches Tool in scripts

```json
"post-install-cmd": ["php vendor/bin/magento-patches apply ACSD-48570 MDVA-30106 …"]
```

Run `composer patches:quality` with the same ids (`magento/quality-patches` stays a dependency; it
is the data source). The plugin picks the file for the installed versions, follows `require` /
`replaced-with`, and drops ids your release already contains — so the list usually gets shorter.
After an upgrade, `composer patches:quality --resync`.

`magento/magento-cloud-patches` / `ece-tools` are only needed on Adobe Commerce Cloud.

## D. composer-patches 1.x

Your own fixes in `composer.patches.json` keep working — the plugin never touches entries without
a `[SEC]` / `[QPT]` / `[CUSTOM]` label, and `patches:status` verifies them too. But 2.x applies
patches more strictly than 1.x, and some that "worked" break on the first install in step 4:

| 1.x behaviour | 2.x | Fix |
|---|---|---|
| tried `-p1`, `-p0`, `-p2`, `-p4` until one fit | one depth: `-p1` unless configured | patches written as `a/vendor/<vendor>/<pkg>/…` need depth 4: `"package-depths": {"onelogin/php-saml": 4}` under `extra.composer-patches`, `"depth": 4` on the entry, or re-add it with `patches:add` (rewritten package-relative) |
| fell back to `patch` with fuzz | `git apply`, no fuzz | regenerate the patch against the installed version (seen: patches applied "with fuzz 1" for years) |
| one file of a patch missing (e.g. a test file not in the dist) → rest still applied | whole patch fails | remove the hunks for files the package doesn't ship |
| patch file missing → warning | install fails | remove the entry or restore the file |

Finding them before step 4: a patch that only applies with fuzz shows up as neither applied nor
applicable in `git apply --check -R -p<depth>` against today's `vendor/`.

Optional: re-add custom patches with `patches:add <file> --name=<ticket>` — paths like
`a/vendor/<vendor>/<pkg>/…` are mapped to their package; add `--package=<vendor/pkg>` for a patch
written relative to the package (`a/Model/…`). That validates them like security patches, stores them package-relative (`-p1`) under
`patches/<vendor>/<pkg>/custom/`, and lets `patches:upgrade` carry them over. Remove the old entry in
the same branch.

## E. Inline `extra.patches`

composer-patches 2.x still applies them, but the plugin only manages and verifies the patches file.
Move the entries into it (same format: `"package": {"description": "path"}`).

## F. composer-patches 2.x already

Nothing to switch: the plugin uses `extra.composer-patches.patches-file` and keeps your
`package-depths`. Continue with step 3 for any remaining scripts.

## G. Hand-split cumulative files

Folders like `composer-patches/246p15-2026-07-001-CE/magento-framework.patch` (one file per package,
cut by hand) are what the plugin now produces automatically. Delete them and register the original
download (step 5). Leftovers for a version you no longer run can go too.
