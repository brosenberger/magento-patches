#!/usr/bin/env bash
# Builds a fake Composer-based Magento project (path packages) plus test patches.
# Usage: fixtures.sh <work-dir> <plugin-dir>
set -euo pipefail
WORK=$1
PLUGIN=$2
P=$WORK/packages
SHOP=$WORK/shop
IN=$WORK/patches-in
W=$WORK/tmp
rm -rf "$P" "$SHOP" "$IN" "$W"
mkdir -p "$P" "$SHOP" "$IN" "$W"

pkg() { # dir name version extra-json
  mkdir -p "$P/$1"
  printf '{ "name": "%s", "version": "%s", "type": "library"%s }\n' "$2" "$3" "$4" > "$P/$1/composer.json"
}

# ---------- magento2-base (deploys lib/web to the project root via "map") ----------
for v in 2.4.8 2.4.8-p1; do
  d=magento2-base-$v
  date=$([ "$v" = 2.4.8 ] && echo 2025-04-08 || echo 2025-06-10)
  pkg "$d" magento/magento2-base "$v" ', "time": "'"$date"'", "extra": { "map": [["lib/web", "lib/web"]] }'
  mkdir -p "$P/$d/lib/web/mage"
  printf 'define([], function () { return {}; });\n' > "$P/$d/lib/web/mage/other.js"
  cat > "$P/$d/lib/web/mage/menu.js" <<'EOF'
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var items = $(element).find('li');

        items.on('click', function () {
            $(this).toggleClass('open');
        });
    };
});
EOF
done

# ---------- module-email ----------
for v in 101.2.8 101.2.9; do
  d=module-email-$v
  pkg "$d" magento/module-email "$v" ''
  mkdir -p "$P/$d/Model"
  cat > "$P/$d/Model/Dummy.php" <<'EOF'
<?php
namespace Magento\Email\Model;

class Dummy
{
    public function a()
    {
        return 1;
    }

    /**
     * doc
     */
    public function b()
    {
        return 2;
    }

    /**
     * doc
     */
    public function c()
    {
        return 3;
    }
}
EOF
  if [ -n "${TRANSPORT_PHP:-}" ]; then cp "$TRANSPORT_PHP" "$P/$d/Model/Transport.php"; fi
  cat > "$P/$d/Model/Mailer.php" <<'EOF'
<?php
namespace Magento\Email\Model;

class Mailer
{
    public function from()
    {
        return 'shop';
    }

    public function to()
    {
        return 'customer';
    }

    public function retries()
    {
        return 3;
    }
}
EOF
  mkdir -p "$P/$d/view/frontend/web/js"
  cat > "$P/$d/view/frontend/web/js/widget.js" <<'EOF'
define(['jquery'], function ($) {
    'use strict';

    function init(config) {
        return config;
    }

    return function (config, element) {
        init(config);
        $(element).show();
    };
});
EOF
done

# ---------- module-catalog ----------
for v in 104.0.8 104.0.9; do
  d=module-catalog-$v
  pkg "$d" magento/module-catalog "$v" ''
  mkdir -p "$P/$d/Model" "$P/$d/etc"
  setter=$([ "$v" = 104.0.8 ] && echo '$this->name = $name;' || echo '$this->name = strip_tags($name);')
  cat > "$P/$d/Model/Product.php" <<EOF
<?php
namespace Magento\\Catalog\\Model;

class Product
{
    private string \$name = '';

    public function setName(string \$name): self
    {
        $setter

        return \$this;
    }

    public function getName(): string
    {
        return \$this->name;
    }
}
EOF
  cat > "$P/$d/etc/di.xml" <<'EOF'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <preference for="Magento\Catalog\Api\ProductInterface" type="Magento\Catalog\Model\Product"/>
</config>
EOF
done

# ---------- product metapackages ----------
mkdir -p "$P/product-2.4.8" "$P/product-2.4.8-p1"
echo '{ "name": "magento/product-community-edition", "version": "2.4.8", "type": "metapackage", "require": { "magento/magento2-base": "2.4.8", "magento/module-email": "101.2.8", "magento/module-catalog": "104.0.8" } }' > "$P/product-2.4.8/composer.json"
echo '{ "name": "magento/product-community-edition", "version": "2.4.8-p1", "type": "metapackage", "require": { "magento/magento2-base": "2.4.8-p1", "magento/module-email": "101.2.9", "magento/module-catalog": "104.0.9" } }' > "$P/product-2.4.8-p1/composer.json"

# ---------- a deployer like magento/magento-composer-installer: copies every "map" entry to the root, overwriting ----------
mkdir -p "$P/deployer/src"
cat > "$P/deployer/composer.json" <<'JSON'
{ "name": "test/deployer", "version": "1.0.0", "type": "composer-plugin",
  "require": { "composer-plugin-api": "^2.0" },
  "autoload": { "psr-4": { "Test\\Deployer\\": "src/" } },
  "extra": { "class": "Test\\Deployer\\Plugin" } }
JSON
cat > "$P/deployer/src/Plugin.php" <<'PHP'
<?php
namespace Test\Deployer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    public function activate(Composer $composer, IOInterface $io): void { $this->composer = $composer; }
    public function deactivate(Composer $composer, IOInterface $io): void {}
    public function uninstall(Composer $composer, IOInterface $io): void {}
    public static function getSubscribedEvents(): array
    {
        return [ScriptEvents::POST_INSTALL_CMD => 'deploy', ScriptEvents::POST_UPDATE_CMD => 'deploy'];
    }
    public function deploy(Event $event): void
    {
        $im = $this->composer->getInstallationManager();
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            foreach ((array) ($package->getExtra()['map'] ?? []) as [$source, $target]) {
                $from = $im->getInstallPath($package) . '/' . $source;
                $it = is_dir($from) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) : [new \SplFileInfo($from)];
                foreach ($it as $file) {
                    $rel = is_dir($from) ? substr($file->getPathname(), strlen($from)) : '';
                    $to = getcwd() . '/' . $target . $rel;
                    @mkdir(dirname($to), 0777, true);
                    copy($file->getPathname(), $to);
                }
            }
        }
    }
}
PHP

# ---------- the shop ----------
if [ -n "${OFFLINE_GIT:-}" ]; then
  EXTRA_REPOS='{ "type": "git", "url": "https://github.com/cweagans/composer-patches.git", "no-api": true },
        { "type": "git", "url": "https://github.com/cweagans/composer-configurable-plugin.git", "no-api": true },
        { "packagist.org": false },'
else
  EXTRA_REPOS=''
fi
cat > "$SHOP/composer.json" <<EOF
{
    "name": "test/shop",
    "type": "project",
    "repositories": [
        $EXTRA_REPOS
        { "type": "path", "url": "$P/*", "options": { "symlink": false } },
        { "type": "path", "url": "$PLUGIN", "options": { "symlink": true } }
    ],
    "require": {
        "magento/product-community-edition": "2.4.8",
        "brocode/magento-patches": "@dev",
        "test/deployer": "1.0.0"
    },
    "config": {
        "secure-http": false,
        "allow-plugins": { "cweagans/composer-patches": true, "brocode/magento-patches": true, "test/deployer": true }
    }
}
EOF
mkdir -p "$SHOP/lib/web/mage" "$SHOP/app/etc"
cp "$P/magento2-base-2.4.8/lib/web/mage/menu.js" "$SHOP/lib/web/mage/menu.js"   # deployed root copy

# ---------- patches, Adobe style: project-relative paths ----------
udiff() { echo "diff --git a/$1 b/$1"; diff -u --label "a/$1" --label "b/$1" "$2" "$3" || true; }
newfile() { echo "diff --git a/$1 b/$1"; echo "new file mode 100644"; diff -u --label /dev/null --label "b/$1" /dev/null "$2" || true; }

cp "$P/module-catalog-104.0.8/Model/Product.php" "$W/Product.php"
cp "$P/module-catalog-104.0.9/Model/Product.php" "$W/Product.fixed.php"
cp "$P/magento2-base-2.4.8/lib/web/mage/menu.js" "$W/menu.js"
sed "s/\$(this).toggleClass('open');/\$(this).toggleClass('open').attr('aria-expanded', \$(this).hasClass('open'));/" "$W/menu.js" > "$W/menu.fixed.js"
printf '<?php\nclass X {}\n' > "$W/x.php"; printf '<?php\nclass X { public $y; }\n' > "$W/x2.php"
mkdir -p "$W/zip/CE" "$W/zip/EE"
{
  udiff vendor/magento/module-catalog/Model/Product.php "$W/Product.php" "$W/Product.fixed.php"
  udiff lib/web/mage/menu.js "$W/menu.js" "$W/menu.fixed.js"
  udiff vendor/magento/module-company/Model/X.php "$W/x.php" "$W/x2.php"
} > "$W/zip/CE/VULN-1001_2.4.8.patch"
udiff vendor/magento/module-staging/Model/X.php "$W/x.php" "$W/x2.php" > "$W/zip/EE/VULN-1001_2.4.8.patch"
echo "not a patch for this version" > "$W/zip/CE/VULN-1001_2.4.7-p10.patch"
(cd "$W/zip" && zip -qr "$IN/APSB26-999.zip" CE EE)
udiff vendor/magento/module-catalog/Model/Product.php "$W/Product.php" "$W/Product.fixed.php" > "$IN/APSB26-998_VULN-1002_2.4.8.patch"

# Adobe's monthly file: compact version in the name, and it ships their vendor/bin/patch-status tool
sed 's/return 1;/return 100;/' "$P/module-email-101.2.8/Model/Dummy.php" > "$W/Dummy.monthly.php"
printf '#!/usr/bin/env php\n<?php echo "status";\n' > "$W/patch-status"
{
  udiff vendor/magento/module-email/Model/Dummy.php "$P/module-email-101.2.8/Model/Dummy.php" "$W/Dummy.monthly.php"
  newfile vendor/bin/patch-status "$W/patch-status"
} > "$IN/248-2026-07-001-CE.patch"

D=$P/module-email-101.2.8/Model/Dummy.php
php -r '
$src = file_get_contents($argv[1]); $w = $argv[2];
$b = "    /**\n     * doc\n     */\n    public function b()"; $c = "    /**\n     * doc\n     */\n    public function c()";
file_put_contents("$w/Dummy.constX.php", str_replace($b, "    private const X = 1;\n\n$b", $src));
file_put_contents("$w/Dummy.constX_at_c.php", str_replace($c, "    private const X = 1;\n\n$c", $src));
file_put_contents("$w/Dummy.constXY.php", str_replace($c, "    private const X = 1;\n    private const Y = 2;\n\n$c", $src));
file_put_contents("$w/Dummy.broken.php", preg_replace("/return 3;/", "return 3", $src, 1));
$m = "    public function d()\n    {\n        return 4;\n    }\n\n";
file_put_contents("$w/Dummy.methodD.php", str_replace($c, $m . $c, $src));
file_put_contents("$w/Dummy.methodD_at_b.php", str_replace($b, $m . $b, $src));
file_put_contents("$w/Dummy.fix997.php", str_replace("return 1;", "return (int) 1;", $src));
$js = file_get_contents($argv[3]);
$f = "    function toggleAll(items) {\n        items.toggle();\n    }\n\n";
file_put_contents("$w/widget.toggle.js", str_replace("    return function (config, element) {", $f . "    return function (config, element) {", $js));
file_put_contents("$w/widget.toggle_top.js", str_replace("    function init(config) {", $f . "    function init(config) {", $js));
' "$D" "$W" "$P/module-email-101.2.8/view/frontend/web/js/widget.js"
JS=$P/module-email-101.2.8/view/frontend/web/js/widget.js
udiff vendor/magento/module-email/Model/Dummy.php "$D" "$W/Dummy.methodD.php" > "$IN/method-d.patch"
udiff vendor/magento/module-email/Model/Dummy.php "$D" "$W/Dummy.methodD_at_b.php" > "$IN/method-d-elsewhere.patch"
udiff vendor/magento/module-email/view/frontend/web/js/widget.js "$JS" "$W/widget.toggle.js" > "$IN/js-function.patch"
udiff vendor/magento/module-email/view/frontend/web/js/widget.js "$JS" "$W/widget.toggle_top.js" > "$IN/js-function-elsewhere.patch"
# a bulletin that a developer only committed (dropped into patches/downloads/ by hand)
udiff vendor/magento/module-email/Model/Dummy.php "$D" "$W/Dummy.fix997.php" > "$IN/APSB26-997_VULN-1003_2.4.8.patch"
udiff vendor/magento/module-email/Model/Dummy.php "$D" "$W/Dummy.constX.php" > "$IN/const-x.patch"
udiff vendor/magento/module-email/Model/Dummy.php "$D" "$W/Dummy.constX_at_c.php" > "$IN/const-x-elsewhere.patch"
udiff vendor/magento/module-email/Model/Dummy.php "$D" "$W/Dummy.constXY.php" > "$IN/const-xy.patch"
udiff vendor/magento/module-email/Model/Dummy.php "$D" "$W/Dummy.broken.php" > "$IN/broken.patch"
cat > "$IN/ambiguous.patch" <<'EOF'
diff --git a/vendor/magento/module-email/Model/Dummy.php b/vendor/magento/module-email/Model/Dummy.php
--- a/vendor/magento/module-email/Model/Dummy.php
+++ b/vendor/magento/module-email/Model/Dummy.php
@@ -40,3 +40,4 @@

     /**
+     * added doc line
      * doc
EOF
printf 'diff --git a/app/code/Vendor/Mod/registration.php b/app/code/Vendor/Mod/registration.php\nnew file mode 100644\n--- /dev/null\n+++ b/app/code/Vendor/Mod/registration.php\n@@ -0,0 +1,2 @@\n+<?php\n+// x\n' > "$IN/unsupported.patch"
printf '<?php\nnamespace Magento\\Catalog\\Model;\n\nclass Helper\n{\n}\n' > "$W/Helper.php"
cp "$P/module-catalog-104.0.8/etc/di.xml" "$W/di.xml"
php -r 'file_put_contents($argv[2], str_replace("</config>", "    <type name=\"Magento\\Catalog\\Model\\Helper\"/>\n</config>", file_get_contents($argv[1])));' "$W/di.xml" "$W/di.new.xml"
{ newfile vendor/magento/module-catalog/Model/Helper.php "$W/Helper.php"; udiff vendor/magento/module-catalog/etc/di.xml "$W/di.xml" "$W/di.new.xml"; } > "$IN/catalog-helper.patch"
printf 'diff --git a/vendor/magento/module-catalog/Model/Product.php b/vendor/magento/module-catalog/Model/Product.php\n--- a/vendor/magento/module-catalog/Model/Product.php\n+++ b/vendor/magento/module-catalog/Model/Product.php\n@@ -16,3 +16,3 @@\n     {\n-        return trim($this->name);\n+        return trim((string) $this->name);\n     }\n' > "$IN/handedit.patch"

# ---------- a fake Adobe: KB articles + patch zips served over HTTP (see run.sh) ----------
WWW=$WORK/www
rm -rf "$WWW"; mkdir -p "$WWW/kbarticles" "$WWW/patch" "$WWW/security" "$WWW/v"
M=$P/module-email-101.2.8/Model/Mailer.php
mailer() { # <name> <from-expr> <retries>
  php -r 'file_put_contents($argv[2], str_replace(["return '"'"'shop'"'"';", "return 3;"], ["return " . $argv[3] . ";", "return " . $argv[4] . ";"], file_get_contents($argv[1])));' "$M" "$W/$1" "$2" "$3"
}
mailer Mailer.v1.php "strip_tags('shop')" 30        # v1: the fix plus a bad change (retries 30)
mailer Mailer.v2.php "strip_tags('shop')" 3         # v2: Adobe removed the bad change
mailer Mailer.v3.php "strip_tags('shop')" 5         # v3: published in an "updated patch" article
for v in v1 v2 v3; do
  mkdir -p "$W/z-$v"
  udiff vendor/magento/module-email/Model/Mailer.php "$M" "$W/Mailer.$v.php" > "$W/z-$v/VULN-2001_2.4.8.patch"
  (cd "$W/z-$v" && zip -qX "$WWW/v/$v.zip" VULN-2001_2.4.8.patch)
done
mkdir -p "$W/z-v2r" && cp "$W/z-v2/VULN-2001_2.4.8.patch" "$W/z-v2r/" && echo "release notes" > "$W/z-v2r/README.txt"
(cd "$W/z-v2r" && zip -qX "$WWW/v/v2-repackaged.zip" VULN-2001_2.4.8.patch README.txt)
cp "$WWW/v/v1.zip" "$WWW/patch/2-4-8-oct-2026.zip"
echo "not for this version" > "$WWW/patch/2-4-7-p10-oct-2026.zip"
# v4: a replacement that does not fit (wrong context) — must be rolled back
mkdir -p "$W/z-v4"
cat > "$W/z-v4/VULN-2001_2.4.8.patch" <<'PATCH'
diff --git a/vendor/magento/module-email/Model/Mailer.php b/vendor/magento/module-email/Model/Mailer.php
--- a/vendor/magento/module-email/Model/Mailer.php
+++ b/vendor/magento/module-email/Model/Mailer.php
@@ -12,3 +12,3 @@
     {
-        return 'someone else';
+        return 'customer@example.com';
     }
PATCH
(cd "$W/z-v4" && zip -qX "$WWW/v/v4.zip" VULN-2001_2.4.8.patch)
