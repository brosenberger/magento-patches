<?php

/**
 * Dependency-free checks for version logic, diff parsing and bulletin parsing.
 *   php tests/unit/run.php
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Brocode\\MagentoPatches\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Brocode\MagentoPatches\Magento\MagentoVersion as V;
use Brocode\MagentoPatches\Patch\DiffParser;
use Brocode\MagentoPatches\Source\BulletinParser;
use Brocode\MagentoPatches\Store\Label;

$failed = 0;
$ok = static function (bool $condition, string $message) use (&$failed): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $condition ? 0 : 1;
};
$date = static fn (string $d): DateTimeImmutable => new DateTimeImmutable($d);

// --- versions -------------------------------------------------------------------------------
$ok(V::parse('2.4.7-p10')->isCoveredBy(V::parse('2.4.7-p10'), null), '"2.4.7-p10 and earlier" covers 2.4.7-p10');
$ok(V::parse('2.4.7-p9')->isCoveredBy(V::parse('2.4.7-p10'), null), '… covers -p9 (numeric, not string compare)');
$ok(!V::parse('2.4.7-p11')->isCoveredBy(V::parse('2.4.7-p10'), null), '… does not cover -p11');
$ok(V::parse('2.4.9-beta1')->isCoveredBy(V::parse('2.4.9'), null), 'GA bound covers its betas');
$ok(!V::parse('2.4.9-p1')->isCoveredBy(V::parse('2.4.9'), null), 'GA bound does not cover -p1');
$ok(V::parse('2.4.7-p10')->isCoveredBy(V::parse('2.4.7-2026-aug'), $date('2026-05-12')), 'monthly bound covers a -p released before it');
$ok(!V::parse('2.4.7-p11')->isCoveredBy(V::parse('2.4.7-2026-aug'), $date('2027-05-11')), 'monthly bound does not cover a later -p');
$ok(V::parse('2.4.7-p10')->isCoveredBy(V::parse('2.4.7-2026-aug'), null), 'unknown release date → treated as affected');
$ok((string) V::parse("2.4.8\u{2011}p4") === '2.4.8-p4', 'non-breaking hyphen (U+2011) understood');
$p = V::parse('2.4.7-p1')->fileNamePattern();
$ok(preg_match($p, 'VULN-1_2.4.7-p1.patch') === 1 && preg_match($p, 'VULN-1_2.4.7-p10.patch') === 0, 'file pattern for -p1 does not match -p10');
$p = V::parse('2.4.7')->fileNamePattern();
$ok(preg_match($p, 'X_2.4.7.patch') === 1 && preg_match($p, 'X_2.4.7-p10.patch') === 0 && preg_match($p, 'X_2-4-7-2026-jul.patch') === 0, 'file pattern for GA excludes -p and monthly builds');

// --- diffs ----------------------------------------------------------------------------------
$plain = "preamble\n--- a/vendor/x/y/a.sql\t2024-01-01\n+++ b/vendor/x/y/a.sql\n@@ -1,3 +1,3 @@\n line1\n---- removed comment\n+-- added\n line3\n"
    . "--- a/vendor/x/y/b.txt\n+++ b/vendor/x/y/b.txt\n@@ -1 +1 @@\n-old\n+new\n\\ No newline at end of file\n";
$chunks = (new DiffParser())->parse($plain);
$ok(count($chunks) === 2 && $chunks[1]->target() === 'vendor/x/y/b.txt', 'plain diff split per file');
$ok($chunks[0]->hunks[0]->removed() === ['--- removed comment'], 'a content line starting with "---" stays content');
$git = "diff --git a/vendor/a/b/Old.php b/vendor/a/b/New.php\nsimilarity index 90%\nrename from vendor/a/b/Old.php\nrename to vendor/a/b/New.php\n--- a/vendor/a/b/Old.php\n+++ b/vendor/a/b/New.php\n@@ -1 +1 @@\n-x\n+y\n"
    . "diff --git a/vendor/a/b/gone.php b/vendor/a/b/gone.php\ndeleted file mode 100644\n--- a/vendor/a/b/gone.php\n+++ /dev/null\n@@ -1 +0,0 @@\n-z\n";
$chunks = (new DiffParser())->parse($git);
$ok($chunks[0]->isRename() && $chunks[1]->isDeletion(), 'git rename and deletion recognised');
$ok(str_contains($chunks[0]->withPaths('Old.php', 'New.php')->render(), "--- a/Old.php\n+++ b/New.php"), 'rendered relative to the package');
try {
    (new DiffParser())->parse("diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1,3 +1,3 @@\n a\n-b\n");
    $ok(false, 'truncated patch rejected');
} catch (RuntimeException) {
    $ok(true, 'truncated patch rejected');
}

// --- labels ---------------------------------------------------------------------------------
$label = Label::make(Label::QUALITY, 'ACSD-1', str_repeat('long title ', 20), 'file.patch');
$ok(Label::matches($label, Label::QUALITY, 'acsd-1') && str_ends_with($label, '[file.patch]'), 'labels: truncated title keeps the file suffix');

// --- bulletin -------------------------------------------------------------------------------
$html = (string) file_get_contents(__DIR__ . '/apsb-sample.html');
$b = (new BulletinParser())->parse('APSB26-146', 'https://example', $html);
$ok($b->date === 'September 7, 2026' && $b->priority === '1', 'bulletin: date and priority');
$ok((string) $b->affectedBound('Magento Open Source', '2.4.8') === '2.4.8-2026-aug', 'bulletin: affected bound per line');
$ok((string) $b->affectedBound('Adobe Commerce', '2.4.7') === '2.4.7-p10', 'bulletin: Commerce row separate from B2B row');
$ok($b->solutionFor('Magento Open Source', '2.4.8') === null && str_contains($b->solutionText('Magento Open Source'), 'Hotfix'), 'bulletin: hotfix without version');
$ok($b->linksFor('Magento Open Source') === ['https://experienceleague.adobe.com/en/docs/experience-cloud-kcs/kbarticles/ka-49999'], 'bulletin: download link');
$ok($b->exploited && $b->cves === ['CVE-2026-75650'], 'bulletin: exploited + CVE');

// --- stored bulletin data -------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/mp-unit-' . bin2hex(random_bytes(3));
mkdir($tmp);
$store = new Brocode\MagentoPatches\Source\BulletinStore("$tmp/patches/security-bulletins.json");
$store->setIndex(['APSB26-5', 'APSB26-146']);
$store->put($b);
$ok($store->save() && is_file("$tmp/patches/security-bulletins.json"), 'store: written');
$reloaded = new Brocode\MagentoPatches\Source\BulletinStore("$tmp/patches/security-bulletins.json");
$copy = $reloaded->get('apsb26-146');
$ok($copy !== null && $copy->toArray() === $b->toArray(), 'store: bulletin survives a round trip unchanged');
$ok((string) $copy->affectedBound('Magento Open Source', '2.4.8') === '2.4.8-2026-aug' && $copy->exploited, 'store: reloaded data is usable');
$ok($reloaded->knownIds() === ['APSB26-05', 'APSB26-146'] && $reloaded->indexCheckedAt() !== null, 'store: index ids normalised, check time kept');
$reloaded->put($copy);
$ok($reloaded->save() === false, 'store: unchanged bulletin does not rewrite the file');

// --- downloads ------------------------------------------------------------------------------
mkdir("$tmp/in");
file_put_contents("$tmp/in/APSB26-153.zip", 'zip-bytes');
$downloads = new Brocode\MagentoPatches\Store\Downloads("$tmp/patches/downloads");
$writes = $downloads->planStore('APSB26-153', ["$tmp/in/APSB26-153.zip"]);
$ok(array_keys($writes) === [$downloads->dir . '/APSB26-153/APSB26-153.zip'], 'downloads: stored under <ID>/ with its own name');
foreach ($writes as $path => $content) {
    mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}
$ok($downloads->planStore('APSB26-153', ["$tmp/in/APSB26-153.zip"]) === [], 'downloads: identical file is not stored twice');
file_put_contents($downloads->dir . '/apsb26-7_vuln.patch', "x\n");
file_put_contents($downloads->dir . '/notes.patch', "x\n");
$all = $downloads->all();
$ok(isset($all['APSB26-153'], $all['APSB26-07'], $all['']), 'downloads: folders, loose files with id, and unknown files are listed');

// --- KB article -----------------------------------------------------------------------------
$kb = Brocode\MagentoPatches\Source\KbArticle::parse('https://experienceleague.adobe.com/en/docs/experience-cloud-kcs/kbarticles/ka-49323', <<<'HTML'
<html><head><meta name="last-update" content="2026-10-16T20:11:29.000Z"></head><body>
<p>2.4.8-p5 merchants: refer to <a href="/en/docs/experience-cloud-kcs/kbarticles/ka-50927">Updated October 2026 Isolated security patch for Adobe Commerce 2.4.8-p5</a></p>
<p>See also <a href="/en/docs/experience-cloud-kcs/kbarticles/ka-12345">How to apply a composer patch</a></p>
<a href="https://repo.magento.com/patch/2-4-9-oct-2026.zip">2-4-9-oct-2026.zip</a>
<a href="https://repo.magento.com/patch/2-4-7-p10-oct-2026.zip">2-4-7-p10-oct-2026.zip</a>
</body></html>
HTML);
$ok($kb->lastUpdate === '2026-10-16T20:11:29.000Z', 'kb: last update from meta');
$ok(array_column($kb->files, 'name') === ['2-4-9-oct-2026.zip', '2-4-7-p10-oct-2026.zip'], 'kb: file links');
$ok($kb->related === ['https://experienceleague.adobe.com/en/docs/experience-cloud-kcs/kbarticles/ka-50927'], 'kb: only the "updated patch" article counts as related');
$p = V::parse('2.4.7-p10')->fileNamePattern();
$ok(preg_match($p, '2-4-7-p10-oct-2026.zip') === 1 && preg_match(V::parse('2.4.9')->fileNamePattern(), '2-4-9-oct-2026.zip') === 1, 'kb: repo.magento.com file names match the installed version');

exec('rm -rf ' . escapeshellarg($tmp));
echo $failed === 0 ? "---- all passed\n" : "---- {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
