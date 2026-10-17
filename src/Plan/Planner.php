<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Plan;

use Brocode\MagentoPatches\Patch\Chunk;
use Brocode\MagentoPatches\Patch\Location;
use Brocode\MagentoPatches\Patch\PackageLocator;
use Brocode\MagentoPatches\Patch\Stage;
use Brocode\MagentoPatches\Patch\Validator;
use Brocode\MagentoPatches\Patch\Verdict;
use Brocode\MagentoPatches\Store\Label;
use Brocode\MagentoPatches\Store\PatchesFile;
use Composer\Util\ProcessExecutor;

/**
 * Turns sources into per-package patch files, validating every file chunk against the
 * state it will meet. Nothing is written here — a plan with conflicts is simply not executed.
 */
final class Planner
{
    /** @var array<string, true> */
    private array $reserved = [];

    public function __construct(
        private readonly PackageLocator $locator,
        private readonly Validator $validator,
        private readonly PatchesFile $store,
        private readonly ProcessExecutor $process,
        private readonly string $stageRoot,
        private readonly string $root,
        private readonly string $patchDir = 'patches',
        private readonly bool $skipUnsupported = false,
    ) {
    }

    /** @param Source[] $sources */
    public function plan(array $sources, ?Plan $plan = null): Plan
    {
        $plan ??= new Plan();
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

        return $plan;
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
