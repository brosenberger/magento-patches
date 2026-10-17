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

namespace BroCode\MagentoPatches\Plan;

use BroCode\MagentoPatches\Patch\Chunk;
use BroCode\MagentoPatches\Patch\DiffParser;
use BroCode\MagentoPatches\Patch\Location;
use BroCode\MagentoPatches\Patch\PackageLocator;
use BroCode\MagentoPatches\Patch\Pristine;
use BroCode\MagentoPatches\Patch\Stage;
use BroCode\MagentoPatches\Patch\Validator;
use BroCode\MagentoPatches\Patch\Verdict;
use BroCode\MagentoPatches\Store\Label;
use BroCode\MagentoPatches\Store\PatchesFile;
use Composer\Util\ProcessExecutor;

/**
 * Turns sources into per-package patch files, validating every file chunk against the
 * state it will meet. Nothing is written here — a plan with conflicts is simply not executed.
 */
final class Planner
{
    /** @var array<string, true> */
    private array $reserved = [];

    /** @var array<string, string[]> */
    private array $registered = [];

    public function __construct(
        private readonly PackageLocator $locator,
        private readonly Validator $validator,
        private readonly PatchesFile $store,
        private readonly ProcessExecutor $process,
        private readonly string $stageRoot,
        private readonly string $root,
        private readonly string $patchDir = 'patches',
        private readonly bool $skipUnsupported = false,
        private readonly ?Pristine $pristine = null,
    ) {
    }

    /** @param Source[] $sources */
    public function plan(array $sources, ?Plan $plan = null): Plan
    {
        $plan ??= new Plan();
        $dirty = [];
        foreach ($sources as $source) {
            if ($source->chunks === []) {
                $plan->conflicts[] = sprintf('%s: no file changes found in the patch', $source->name());
                continue;
            }

            /** @var array<string, array{path: string, chunks: Chunk[]}> $groups */
            $groups = [];
            foreach ($source->chunks as $chunk) {
                [$location, $relChunk] = $this->locate($source, $chunk);
                $where = sprintf('%s: %s', $source->name(), $chunk->target());
                if ($location->status === Location::IGNORED) {
                    $plan->note('skip', sprintf('%s — %s', $where, $location->reason));
                    continue;
                }
                if ($location->status === Location::NOT_INSTALLED) {
                    $plan->note('skip', sprintf('%s — %s not installed', $where, $location->package));
                    continue;
                }
                if ($location->status === Location::UNSUPPORTED || $relChunk === null) {
                    $text = sprintf('%s — %s', $where, $location->reason ?? 'unsupported target');
                    if ($this->skipUnsupported) {
                        $plan->note('warn', $text . ' (skipped, --skip-unsupported)');
                    } else {
                        $plan->conflicts[] = $text . ' (use --skip-unsupported to leave it out)';
                    }
                    continue;
                }
                $groups[$location->package]['path'] = (string) $location->installPath;
                $groups[$location->package]['chunks'][] = $relChunk;
            }

            foreach ($groups as $package => $group) {
                $label = $source->label ?? Label::make($source->tag, $source->id, $source->detail);
                foreach ($plan->patches as $planned) {
                    if ($planned->package === $package && $planned->label === $label) {
                        // Same id, second file (e.g. Open Source + Commerce file) for the same package.
                        $label = Label::make($source->tag, $source->id, $source->detail, $source->fileName);
                        break;
                    }
                }
                if ($this->store->has($package, $label)) {
                    $plan->note('skip', sprintf('%s → %s: already registered', $source->name(), $package));
                    continue;
                }
                $stage = $plan->stages[$package] ??= new Stage(
                    $package,
                    $group['path'],
                    $this->stageRoot . '/' . $this->slug($package),
                    $this->process
                );

                $applied = [];
                $files = [];
                foreach ($group['chunks'] as $chunk) {
                    $verdict = $this->validator->check($chunk, $stage);
                    $where = sprintf('%s → %s:%s', $source->name(), $package, $chunk->target());
                    switch ($verdict->fit) {
                        case Verdict::APPLY:
                            $applied[] = $chunk->render();
                            $files[] = $chunk->target();
                            foreach ($verdict->notes as $note) {
                                $plan->note('info', $where . ' — ' . $note);
                            }
                            break;
                        case Verdict::ALREADY_APPLIED:
                            if ($source->label === null && !$this->explained($plan, $package, $chunk)) {
                                $plan->conflicts[] = $where . ' — in vendor/, but not in the released package or a registered patch';
                                $dirty[$package] = true;
                                break;
                            }
                            $plan->note('skip', $where . ($source->label !== null
                                ? ' — contained in the installed code now, dropped'
                                : ' — already applied'));
                            break;
                        case Verdict::ALREADY_PRESENT:
                            $plan->note('warn', $where . ' — skipped, ' . $verdict->reason);
                            break;
                        default:
                            $plan->conflicts[] = $where . ' — ' . $verdict->reason;
                    }
                }
                if ($applied !== []) {
                    $content = implode('', $applied);
                    $plan->patches[] = new PlannedPatch($package, $label, $this->patchPath($source, $package, $content), $content, $files);
                }
            }
        }
        if ($dirty !== []) {
            $plan->conflicts[] = "vendor/ was patched outside composer-patches (old patch script, cweagans 1.x, hand edit) — the next `composer install` would undo it.\n"
                . '  Remove the old mechanism, then: composer reinstall ' . implode(' ', array_keys($dirty)) . "\n"
                . '  and run this command again.';
        }

        return $plan;
    }

    /**
     * An "already applied" verdict is only safe to skip when `composer install` will produce that
     * state again: a registered patch (or one planned earlier in this run) touches the file, or the
     * pristine package already contains the change.
     */
    private function explained(Plan $plan, string $package, Chunk $chunk): bool
    {
        if ($this->pristine === null) {
            return true;
        }
        $files = $this->registeredFiles($package);
        foreach ($plan->patches as $planned) {
            if ($planned->package === $package) {
                array_push($files, ...$planned->files);
            }
        }
        if (array_intersect($chunk->paths(), $files) !== []) {
            return true;
        }

        return $this->validator->isApplied($chunk, $this->pristine->stage($package));
    }

    /** @return string[] package-relative files changed by the patches registered for a package */
    private function registeredFiles(string $package): array
    {
        if (!isset($this->registered[$package])) {
            $files = [];
            foreach ($this->store->entries() as $entry) {
                $file = $this->root . '/' . $entry['url'];
                if ($entry['package'] !== $package || !is_file($file)) {
                    continue;
                }
                try {
                    foreach ((new DiffParser())->parse((string) file_get_contents($file)) as $registered) {
                        array_push($files, ...$registered->atDepth($entry['depth'])->paths());
                    }
                } catch (\Throwable) {
                    // unreadable registered patch: cannot vouch for anything it may have changed
                }
            }
            $this->registered[$package] = array_values(array_unique($files));
        }

        return $this->registered[$package];
    }

    /** @return array{0: Location, 1: ?Chunk} */
    private function locate(Source $source, Chunk $chunk): array
    {
        $find = fn (?string $path): ?Location => match (true) {
            $path === null => null,
            $source->package !== null => $this->locator->inPackage($source->package, $path),
            default => $this->locator->locate($path),
        };
        $old = $find($chunk->oldPath);
        $new = $find($chunk->newPath);
        $primary = $new ?? $old;
        if ($primary === null) {
            return [new Location(Location::UNSUPPORTED, reason: 'no path'), null];
        }
        if ($old !== null && $new !== null && ($old->status !== $new->status || $old->package !== $new->package)) {
            return [new Location(Location::UNSUPPORTED, reason: 'rename across packages'), null];
        }
        if (!$primary->isPatchable()) {
            return [$primary, null];
        }

        return [$primary, $chunk->withPaths($old?->relPath, $new?->relPath)];
    }

    private function patchPath(Source $source, string $package, string $content): string
    {
        $base = $this->slug($source->id);
        $file = $this->slug(pathinfo($source->fileName, PATHINFO_FILENAME));
        if ($file !== '' && stripos($file, $base) === 0) {
            $base = $file; // file already named after the id (also keeps stored names stable)
        } elseif ($file !== '') {
            $base .= '--' . $file;
        }
        $dir = sprintf('%s/%s/%s', $this->patchDir, $package, strtolower($source->tag));
        for ($n = 1; ; $n++) {
            $candidate = sprintf('%s/%s%s.patch', $dir, $base, $n > 1 ? '-' . $n : '');
            $absolute = $this->root . '/' . $candidate;
            $clash = isset($this->reserved[$candidate])
                || (is_file($absolute) && file_get_contents($absolute) !== $content);
            if (!$clash) {
                $this->reserved[$candidate] = true;

                return $candidate;
            }
        }
    }

    private function slug(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value), '-');
    }
}
