<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */

declare(strict_types=1);

namespace BroCode\MagentoPatches\Command;

use BroCode\MagentoPatches\Magento\MagentoVersion;
use BroCode\MagentoPatches\Runtime\Context;
use BroCode\MagentoPatches\Source\Bulletin;
use BroCode\MagentoPatches\Source\BulletinCatalog;
use BroCode\MagentoPatches\Source\BulletinRepository;
use BroCode\MagentoPatches\Source\BulletinStore;
use BroCode\MagentoPatches\Source\Fetcher;
use BroCode\MagentoPatches\Source\IsolatedPatchFiles;
use BroCode\MagentoPatches\Source\SecurityAdvisor;
use BroCode\MagentoPatches\Store\DownloadManifest;
use BroCode\MagentoPatches\Store\Downloads;
use BroCode\MagentoPatches\Store\Label;
use Composer\IO\IOInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SecurityCommand extends AbstractPatchCommand
{
    protected function configure(): void
    {
        $this->setName('patches:security')
            ->setDescription('Without files: list Adobe security bulletins that affect the installed version. With files: add (and keep) the downloaded isolated patch')
            ->addArgument('files', InputArgument::IS_ARRAY, 'Downloaded zip / .patch files / folder of an Adobe isolated security patch')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Bulletin id the files belong to (APSB26-153); detected from the file name if possible')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'The given files replace everything stored for this bulletin (re-validated and re-applied)')
            ->addOption('from-version', null, InputOption::VALUE_REQUIRED, 'Use the file made for this version (e.g. 2.4.7-p10) although another one is installed — a backport for a line or -p without its own file. Same checks, all or nothing; Adobe has not tested the combination')
            ->addOption('download', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Download the files for the installed version from the bulletin\'s KB article and add them (needs repo.magento.com keys in auth.json)')
            ->addOption('check-downloads', null, InputOption::VALUE_NONE, 'Re-download every stored file from its source / KB article, compare sha256, and replace what Adobe changed')
            ->addOption('bulletin', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only check these bulletins')
            ->addOption('show', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Print the stored details of a bulletin')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Fetch nothing, also not with --refresh / --check-downloads (the listing is offline anyway)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Re-read the index and all bulletins in range (Adobe sometimes revises bulletins); implies --check-downloads')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Check bulletins from this year on (default: release year of the installed version)')
            ->addOption('qpt-path', null, InputOption::VALUE_REQUIRED, 'Quality Patches checkout, used to know the latest -p release of your line')
            ->addSafetyOptions()
            ->setHelp(<<<'HELP'
<info>composer patches:security</info>
  Compares Adobe's Commerce security bulletins with the version Composer installed and with
  patches.json, using the bulletin data in patches/security-bulletins.json (commit it) — offline.
  --refresh checks Adobe for new or revised bulletins, --check-downloads compares the stored
  downloads with Adobe's.
  Exit code 2 when something is missing.

<info>composer patches:security --refresh</info>      online: new bulletins + stored downloads
<info>composer patches:security --show=APSB26-146</info>  details of one bulletin

<info>composer patches:security ~/Downloads/APSB26-153.zip</info>
<info>composer patches:security https://repo.magento.com/patch/2-4-7-p10-oct-2026.zip --id=APSB26-153</info>
<info>composer patches:security --download=APSB26-153</info>
<info>composer patches:security ~/Downloads/2-4-7-p10-oct-2026.zip --id=APSB26-153 --from-version=2.4.7-p10</info>
  Backport: no file for your version (e.g. Open Source 2.4.6 gets no file from September 2026 on).
  Every change is validated against your code; if one does not fit, nothing is written.
  Keeps the download in patches/downloads/APSB26-153/ (sha256 + source URL in manifest.json),
  picks the files for the installed version, splits them per package, validates and registers
  them. Re-running is safe. A file with the same name but other content replaces the stored one.

<info>composer patches:security --check-downloads</info>   (also part of --refresh)
  Fetches the KB articles of all stored downloads (and their "updated patch" articles),
  re-downloads the files and compares sha256. Files Adobe replaced or superseded are stored anew,
  and the bulletin's patches are re-validated and re-applied — or rolled back if they don't fit.
  Without it, a changed KB article ("Last update") is reported once a day.
HELP);
    }

    /** A plain check is read-only; adding, downloading and replacing are not. */
    protected function mutates(InputInterface $input): bool
    {
        return (array) $input->getArgument('files') !== [] || (array) $input->getOption('download') !== []
            || $input->getOption('check-downloads') || $input->getOption('refresh');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $files = (array) $input->getArgument('files');
        if ($files !== []) {
            return $this->add($input, $files);
        }
        if ((array) $input->getOption('download') !== []) {
            return $this->download($input, array_map([Bulletin::class, 'normalizeId'], (array) $input->getOption('download')));
        }

        return $this->check($input);
    }

    private function fetcher(Context $ctx): Fetcher
    {
        return new Fetcher($ctx->composer->getLoop()->getHttpDownloader(), $this->scratch('fetch'));
    }

    /** @param string[] $inputs files, folders or URLs */
    private function add(InputInterface $input, array $inputs): int
    {
        $ctx = $this->context($input);
        $this->getIO()->write(sprintf('<info>%s</info>', $ctx->info->describe()));
        $id = $input->getOption('id') ? Bulletin::normalizeId((string) $input->getOption('id')) : null;
        foreach ($inputs as $file) {
            $id ??= Downloads::detectId(basename($file));
        }
        if ($id === null || !preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $id)) {
            throw new RuntimeException('Cannot tell which bulletin these files belong to — pass --id=APSB26-153.');
        }

        $local = [];
        $origins = [];
        foreach ($inputs as $in) {
            if (Fetcher::isUrl($in)) {
                $path = $this->fetcher($ctx)->download($in);
                $this->getIO()->write(sprintf('  downloaded <comment>%s</comment> (sha256 %s)', $in, substr((string) hash_file('sha256', $path), 0, 12)));
                $local[] = $path;
                $origins[$path] = $in;
            } else {
                $local[] = $in;
            }
        }

        // Same name as a stored file but other content, or --replace: this is a new version of the download.
        $stored = [];
        foreach ($ctx->downloads->filesFor($id) as $file) {
            $stored[basename($file)] = $file;
        }
        $writes = [];
        $collision = false;
        foreach ($local as $in) {
            $files = is_dir($in) ? glob(rtrim($in, '/') . '/*.{zip,patch,diff}', GLOB_BRACE) ?: [] : [$in];
            foreach ($files as $file) {
                $target = $stored[basename($file)] ?? $ctx->downloads->dirFor($id) . '/' . basename($file);
                $content = (string) file_get_contents($file);
                if (isset($stored[basename($file)]) && hash_file('sha256', $stored[basename($file)]) !== hash('sha256', $content)) {
                    $collision = true;
                }
                $writes[$target] = $content;
                if (isset($origins[$file])) {
                    $origins[$target] = $origins[$file];
                }
            }
        }
        if ($collision || $input->getOption('replace')) {
            $deletes = $input->getOption('replace') ? array_values(array_diff(array_values($stored), array_keys($writes))) : [];

            return $this->replaceSecurity($ctx, $input, $id, $writes, $deletes, $origins, $collision
                ? 'a stored file was given again with different content — treating it as a new version'
                : 'replacing the stored download (--replace)', null, $this->fromVersion($input));
        }

        return $this->addSecurity($ctx, $input, [$id => $local], true, $origins, null, $this->fromVersion($input));
    }

    private function fromVersion(InputInterface $input): ?MagentoVersion
    {
        $raw = $input->getOption('from-version');
        if ($raw === null || $raw === '') {
            return null;
        }
        $version = MagentoVersion::parse((string) $raw);
        if ($version === null) {
            throw new RuntimeException(sprintf('--from-version: "%s" is not a Magento version like 2.4.7-p10.', $raw));
        }

        return $version;
    }

    /** @param string[] $ids */
    private function download(InputInterface $input, array $ids): int
    {
        $io = $this->getIO();
        $ctx = $this->context($input);
        $io->write(sprintf('<info>%s</info>', $ctx->info->describe()));
        $catalog = $this->catalog($ctx, $input, false);
        $bulletins = [];
        foreach ($catalog->bulletins($ids, 0, false, PHP_INT_MAX) as $bulletin) {
            $bulletins[$bulletin->id] = $bulletin;
        }
        $fetcher = $this->fetcher($ctx);
        $collector = new IsolatedPatchFiles($this->scratch('download-collect'));
        $result = 0;
        foreach ($ids as $id) {
            $bulletin = $bulletins[$id] ?? null;
            if ($bulletin === null) {
                $io->writeError(sprintf('<error>%s</error> unknown bulletin', $id));
                $result = 1;
                continue;
            }
            if ($ctx->downloads->filesFor($id) !== [] || $ctx->store->managed(Label::SECURITY, $id) !== []) {
                $io->write(sprintf('%s is already stored — checking it against Adobe instead.', $id));
                $result = max($result, $this->checkDownloads($ctx, $input, true, [$id => $bulletin], [$id]));
                $ctx = $this->context($input);
                continue;
            }
            $manifest = $this->manifest($ctx);
            [$links] = $this->offeredLinks($fetcher, $bulletin, $manifest, true);
            $relevant = $collector->relevantLinks($links, $ctx->info, $this->fromVersion($input));
            $updated = array_values(array_filter($relevant, static fn (array $l): bool => !empty($l['updated'])));
            $relevant = $updated !== [] ? $updated : $relevant;
            if ($relevant === []) {
                $io->writeError(sprintf('<error>%s</error> no download for %s found in the KB article(s)%s', $id, $this->fromVersion($input) ?? $ctx->info->version(), $links === [] ? '' : ":\n  - " . implode("\n  - ", array_column($links, 'url'))));
                $io->writeError(sprintf('Pick one and run: composer patches:security <url> --id=%s', $id));
                $result = 1;
                continue;
            }
            $local = [];
            $origins = [];
            foreach ($relevant as $link) {
                $path = $fetcher->download($link['url']);
                $io->write(sprintf('  downloaded <comment>%s</comment>', $link['url']));
                $local[] = $path;
                $origins[$path] = $link['url'];
            }
            $result = max($result, $this->addSecurity($ctx, $input, [$id => $local], true, $origins, $manifest, $this->fromVersion($input)));
            $ctx = $this->context($input);
        }

        return $result;
    }

    /**
     * Files offered by a bulletin's KB articles (and linked "updated patch" articles). Records a
     * first snapshot in the manifest; reports whether an article changed since the last snapshot.
     *
     * @return array{0: list<array{name: string, url: string, updated: bool}>, 1: string[]} links (updated = from an "updated patch" article), changed article descriptions
     */
    private function offeredLinks(Fetcher $fetcher, Bulletin $bulletin, DownloadManifest $manifest, bool $updateSnapshot): array
    {
        $urls = [];
        foreach (array_keys($bulletin->solution) as $product) {
            array_push($urls, ...$bulletin->linksFor($product));
        }
        $links = [];
        $changed = [];
        $articles = $fetcher->kbWithRelated(array_values(array_unique($urls)));
        $relatedUrls = [];
        foreach ($articles as $article) {
            array_push($relatedUrls, ...$article->related);
        }
        foreach ($articles as $article) {
            $updated = in_array($article->url, $relatedUrls, true);
            foreach ($article->files as $file) {
                $links[$file['url']] = $file + ['updated' => $updated || ($links[$file['url']]['updated'] ?? false)];
            }
            $old = $manifest->kb($article->url);
            $oldUrls = $old ? array_column((array) $old['files'], 'url') : [];
            $newUrls = array_column($article->files, 'url');
            sort($oldUrls);
            sort($newUrls);
            if ($old === null && $updated && $manifest->filesFor($bulletin->id) !== []) {
                $changed[] = sprintf('%s (new "updated patch" article)', $article->url);
            } elseif ($old !== null && (($old['lastUpdate'] ?? null) !== $article->lastUpdate || $oldUrls !== $newUrls)) {
                $changed[] = sprintf('%s (last update %s → %s%s)', $article->url, $old['lastUpdate'] ?? '?', $article->lastUpdate ?? '?', $oldUrls !== $newUrls ? ', files changed' : '');
            }
            if ($old === null || $updateSnapshot) {
                $manifest->setKb($article->url, $article->lastUpdate, $article->files, $article->related);
            }
        }

        return [array_values($links), $changed];
    }

    /**
     * Compare every stored download with what Adobe offers now. $force re-downloads and replaces;
     * otherwise only changed KB articles are reported.
     *
     * @param array<string, Bulletin> $bulletins id => stored bulletin data (KB links)
     * @param string[]|null $onlyIds limit to these bulletins
     */
    private function checkDownloads(Context $ctx, InputInterface $input, bool $force, array $bulletins, ?array $onlyIds = null): int
    {
        $io = $this->getIO();
        $fetcher = $this->fetcher($ctx);
        $collector = new IsolatedPatchFiles($this->scratch('check-collect'));
        $result = 0;
        $manifest = $this->manifest($ctx);
        $ids = array_values(array_filter(array_unique(array_merge(array_keys($ctx->downloads->all()), $manifest->ids())), static fn (string $id): bool => $id !== ''));
        foreach ($ids as $id) {
            if ($onlyIds !== null && !in_array($id, $onlyIds, true)) {
                continue;
            }
            $bulletin = $bulletins[$id] ?? null;
            $manifest = $this->manifest($ctx);
            try {
                [$links, $changedArticles] = $bulletin ? $this->offeredLinks($fetcher, $bulletin, $manifest, $force) : [[], []];
            } catch (RuntimeException $e) {
                $io->writeError(sprintf('<warning>%s: %s</warning>', $id, $e->getMessage()));
                continue;
            }
            if (!$force) {
                foreach ($changedArticles as $article) {
                    $io->write(sprintf('<warning>%s: KB article changed since the download was stored: %s — run composer patches:security --check-downloads</warning>', $id, $article));
                }
                if (!$input->getOption('dry-run')) {
                    file_put_contents($manifest->path, $manifest->encode()); // first snapshots
                }
                continue;
            }

            $writes = $deletes = $origins = $reasons = [];
            $linkUrls = array_column($links, 'url');
            // Files from an "updated patch" article supersede the original ones for this version.
            $relevant = $collector->relevantLinks($links, $ctx->info);
            $updated = array_values(array_filter($relevant, static fn (array $l): bool => !empty($l['updated'])));
            $target = $updated !== [] ? $updated : $relevant;
            $superseded = $updated !== [] ? array_diff(array_column($relevant, 'url'), array_column($updated, 'url')) : [];
            $storedEntries = $manifest->filesFor($id);
            foreach ($ctx->downloads->filesFor($id) as $file) {
                $storedEntries[$ctx->downloads->rel($file)] ??= ['sha256' => hash_file('sha256', $file), 'source' => null];
            }
            $knownSources = [];
            $withoutSource = [];
            foreach ($storedEntries as $rel => $entry) {
                $abs = $ctx->downloads->abs($rel);
                $source = $entry['source'] ?? null;
                if ($source === null) {
                    $withoutSource[$rel] = $entry;
                    continue;
                }
                $knownSources[] = $source;
                if ($links !== [] && !in_array($source, $linkUrls, true)) {
                    $deletes[] = $abs;
                    $reasons[] = basename($rel) . ' is no longer offered';
                    continue;
                }
                if (in_array($source, $superseded, true)) {
                    $deletes[] = $abs;
                    $reasons[] = basename($rel) . ' is superseded by an updated patch';
                    continue;
                }
                try {
                    $content = (string) file_get_contents($fetcher->download($source));
                } catch (RuntimeException $e) {
                    $io->writeError(sprintf('<warning>%s: %s</warning>', $id, $e->getMessage()));
                    continue;
                }
                if (hash('sha256', $content) !== ($entry['sha256'] ?? null)) {
                    $writes[$abs] = $content;
                    $origins[$abs] = $source;
                    $reasons[] = sprintf('%s was replaced on the server (sha256 %s → %s)', basename($rel), substr((string) ($entry['sha256'] ?? '?'), 0, 12), substr(hash('sha256', $content), 0, 12));
                } else {
                    $manifest->touchChecked($rel);
                    $io->write(sprintf('  <info>✓</info> %s: %s unchanged on the server', $id, basename($rel)));
                }
            }
            $candidates = false;
            foreach ($target as $link) {
                if (in_array($link['url'], $knownSources, true)) {
                    continue;
                }
                try {
                    $content = (string) file_get_contents($fetcher->download($link['url']));
                } catch (RuntimeException $e) {
                    $io->writeError(sprintf('<warning>%s: %s</warning>', $id, $e->getMessage()));
                    continue;
                }
                $sha = hash('sha256', $content);
                foreach ($withoutSource as $rel => $entry) {
                    if (($entry['sha256'] ?? null) === $sha) {
                        $manifest->setSource($rel, $link['url']); // same bytes as the manual download
                        $manifest->touchChecked($rel);
                        unset($withoutSource[$rel]);
                        $io->write(sprintf('  <info>✓</info> %s: %s matches %s — now tracked', $id, basename($rel), $link['url']));
                        continue 2;
                    }
                }
                $target = $ctx->downloads->dirFor($id) . '/' . $link['name'];
                $writes[$target] = $content;
                $origins[$target] = $link['url'];
                $reasons[] = 'new file offered: ' . $link['name'];
                $candidates = true;
            }
            if ($candidates) {
                foreach (array_keys($withoutSource) as $rel) {
                    if (!isset($writes[$ctx->downloads->abs($rel)])) {
                        $deletes[] = $ctx->downloads->abs($rel); // superseded manual download
                    }
                }
            }

            if ($writes === [] && $deletes === []) {
                if (!$input->getOption('dry-run')) {
                    file_put_contents($manifest->path, $manifest->encode());
                }
                continue;
            }
            $result = max($result, $this->replaceSecurity($ctx, $input, $id, $writes, array_values(array_unique($deletes)), $origins, 'outdated download — ' . implode('; ', $reasons), $manifest));
            $ctx = $this->context($input);
        }

        return $result;
    }

    private function catalog(Context $ctx, InputInterface $input, bool $offline): BulletinCatalog
    {
        return new BulletinCatalog(
            new BulletinStore($ctx->bulletinsFile),
            $offline ? null : new BulletinRepository($ctx->composer->getLoop()->getHttpDownloader()),
            $this->getIO(),
        );
    }

    private function check(InputInterface $input): int
    {
        $io = $this->getIO();
        $ctx = $this->context($input);
        $info = $ctx->info;
        $io->write(sprintf('<info>%s</info>', $info->describe()));

        // Offline by default: Adobe is only contacted for --refresh (new bulletins) and --check-downloads.
        $force = $input->getOption('check-downloads') || $input->getOption('refresh');
        $offline = $input->getOption('offline') || !$force;
        $catalog = $this->catalog($ctx, $input, $offline);
        $only = array_merge((array) $input->getOption('bulletin'), (array) $input->getOption('show'));
        $since = (int) ($input->getOption('since') ?: ($info->releaseDate()?->format('Y') ?? ((int) date('Y') - 2)));
        $bulletins = $catalog->bulletins($only, $since, (bool) $input->getOption('refresh'));
        if ($offline && $only === [] && (new BulletinStore($ctx->bulletinsFile))->indexCheckedAt() === null) {
            // No stored index: "nothing missing" would be a false all-clear.
            $io->writeError(sprintf(
                '<error>No bulletin data in %s yet — cannot tell what is missing.</error> Run `composer patches:security --refresh%s` once and commit the file.',
                $info->relative($ctx->bulletinsFile),
                $this->isProduction() ? ' --force' : ''
            ));

            return 1;
        }

        // Stored downloads: compared with Adobe for --check-downloads / --refresh only.
        $result = 0;
        if (!$offline && ($ctx->downloads->all() !== [] || $this->manifest($ctx)->ids() !== [])) {
            $store = new BulletinStore($ctx->bulletinsFile);
            $byId = [];
            foreach (array_unique(array_merge(array_keys($ctx->downloads->all()), $this->manifest($ctx)->ids())) as $id) {
                if ($id !== '' && ($b = $store->get($id)) !== null) {
                    $byId[$id] = $b;
                }
            }
            $io->write($force ? 'Checking stored downloads against Adobe …' : 'Checking KB articles of stored downloads …');
            $result = $this->checkDownloads($ctx, $input, $force, $byId);
            $ctx = $this->context($input);
        } elseif ($force && $offline) {
            $io->writeError('<warning>--check-downloads needs network access — skipped (offline).</warning>');
        }

        $advisor = new SecurityAdvisor($info, $ctx->store);
        $downloads = $ctx->downloads->all();

        if ($input->getOption('show')) {
            foreach ($bulletins as $bulletin) {
                $this->show($bulletin, $advisor->evaluate($bulletin), $ctx, isset($downloads[$bulletin->id]));
            }

            return 0;
        }

        $missing = 0;
        foreach ($bulletins as $bulletin) {
            $id = $bulletin->id;
            $advice = $advisor->evaluate($bulletin);
            if ($advice === null) {
                $io->write(sprintf('  %s %s — not affected', $id, $bulletin->date ?? ''), true, IOInterface::VERBOSE);
                continue;
            }
            if ($ctx->store->managed(Label::SECURITY, $id) !== []) {
                $io->write(sprintf('  <info>✓</info> %s %s — registered', $id, $bulletin->date ?? ''));
                continue;
            }
            if ($ctx->store->isCovered($id)) {
                $io->write(sprintf('  <info>✓</info> %s %s — covered: %s', $id, $bulletin->date ?? '', $ctx->store->coveredNote($id)));
                continue;
            }
            $missing++;
            $io->write(sprintf(
                "\n<error> %s </error> %s, priority %s%s — affects %s (\"%s and earlier\")",
                $id,
                $bulletin->date ?? 'date unknown',
                $bulletin->priority ?? '?',
                $bulletin->exploited ? ', <error>EXPLOITED IN THE WILD</error>' : '',
                $advice['product'] . ' ' . implode('+', $advice['components']),
                $advice['bound']
            ));
            $io->write('    bulletin: ' . $bulletin->url);
            if (isset($downloads[$id])) {
                $io->write(sprintf('    stored:   %s — not registered yet', $ctx->info->relative($ctx->downloads->dirFor($id))));
                $io->write('    then:     composer patches:sync');
                continue;
            }
            if ($advice['action'] === 'upgrade') {
                $io->write(sprintf('    fix:      upgrade to <info>%s</info> — composer patches:upgrade %s', $advice['solution'], $advice['solution']));
                continue;
            }
            foreach ($bulletin->linksFor($advice['product']) as $link) {
                $io->write('    download: ' . $link);
            }
            $io->write(sprintf(
                '    file:     the one for <info>%s</info>%s (%s)',
                $info->version(),
                $advice['solution'] ? sprintf(' / %s', $advice['solution']) : '',
                $advice['action'] === 'hotfix' ? 'hotfix: ' . $bulletin->solutionText($advice['product']) : 'isolated patch'
            ));
            $io->write(sprintf('    then:     composer patches:security --download=%s   (or: composer patches:security <file> --id=%s)', $id, $id));
        }

        $latest = $this->latestRelease($ctx, $input);
        if ($latest !== null && $latest->compareRank($info->version()) > 0) {
            $io->write(sprintf(
                "\n<warning>%s is not the latest release of %s (%s). Isolated patches are only tested on the latest -p.</warning>",
                $info->version(),
                $info->version()->line,
                $latest
            ));
        }
        if ($catalog->unknown !== []) {
            // Never report "nothing missing" for bulletins that could not be read.
            $io->writeError(sprintf(
                "\n<error>Could not check %s</error> — not fetched and not in %s. Their status is unknown; open them by hand or retry later.",
                implode(', ', $catalog->unknown),
                $info->relative($ctx->bulletinsFile)
            ));
        }
        $io->write(match (true) {
            $missing > 0 => sprintf("\n<comment>%d bulletin(s) missing — apply them in the order listed.</comment>", $missing),
            $catalog->unknown === [] => "\n<info>No missing security bulletins for the installed version.</info>",
            default => "\n<comment>No missing bulletin among those that could be checked.</comment>",
        });

        if ($result !== 0) {
            return $result;
        }
        if ($missing === 0 && $catalog->unknown !== []) {
            return 1;
        }

        return $missing === 0 ? 0 : 2;
    }

    /** @param array<string, mixed>|null $advice */
    private function show(Bulletin $bulletin, ?array $advice, Context $ctx, bool $downloaded): void
    {
        $io = $this->getIO();
        $io->write(sprintf(
            "\n<info>%s</info>  %s  priority %s%s",
            $bulletin->id,
            $bulletin->date ?? '',
            $bulletin->priority ?? '?',
            $bulletin->exploited ? '  <error>EXPLOITED IN THE WILD</error>' : ''
        ));
        $io->write('  ' . $bulletin->url);
        if ($bulletin->cves !== []) {
            $io->write('  CVEs: ' . implode(', ', $bulletin->cves));
        }
        $data = $bulletin->toArray();
        foreach ($data['affected'] as $product => $versions) {
            $io->write(sprintf('  affected  %-20s %s', $product, implode(', ', $versions)));
        }
        foreach ($data['solution'] as $product => $solution) {
            $io->write(sprintf('  solution  %-20s %s', $product, implode(', ', $solution['versions']) ?: $solution['text']));
            foreach ($solution['links'] as $link) {
                $io->write(sprintf('            %-20s %s', '', $link));
            }
        }
        $state = match (true) {
            $advice === null => 'not affected',
            $ctx->store->managed(Label::SECURITY, $bulletin->id) !== [] => 'affected — registered',
            $ctx->store->isCovered($bulletin->id) => 'affected — covered (' . $ctx->store->coveredNote($bulletin->id) . ')',
            $downloaded => 'affected — downloaded, not registered (composer patches:sync)',
            default => 'affected — MISSING',
        };
        $io->write(sprintf('  installed %s: %s', $ctx->info->version(), $state));
    }
}
