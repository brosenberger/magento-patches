<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Patch;

use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

/**
 * Decides per file chunk whether it may be added, in four layers:
 *
 *  1. reverse check      – the exact change is already there (re-run, upstream fix)  → skip
 *  2. declarations       – everything it declares (PHP: const, function/method, property, use,
 *                          class, case; JS: function declarations/expressions/methods)
 *                          already exists elsewhere in the file                        → skip (warn)
 *                          only some of it exists                                       → conflict
 *  3. forward check      – must apply; a hunk landing at an offset is only accepted if its
 *                          context is unique in the file (no "re-applies 10 lines lower")
 *  4. apply + lint       – really applied to the staged copy; php -l / xmllint / node --check
 *                          must not get worse than before                               → else conflict
 *
 * Uses `git apply -p1`, the same patcher composer-patches 2.x uses, so the verdict matches
 * what happens later during `composer install`.
 */
final class Validator
{
    private const DECLARATIONS = [
        'class' => '/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/i',
        'const' => '/^\s*(?:(?:final|public|protected|private)\s+)*const\s+(?:[\w\\\\|?]+\s+)?(\w+)\s*=/i',
        'function' => '/^\s*(?:(?:final|abstract|public|protected|private|static)\s+)*function\s+&?\s*(\w+)\s*\(/i',
        'property' => '/^\s*(?:(?:public|protected|private|var|static|readonly)(?:\(set\))?\s+)+(?:[\w\\\\|?]+\s+)?\$(\w+)\b/i',
        'use' => '/^\s*use\s+(?:function\s+|const\s+)?\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/i',
        'case' => '/^\s*case\s+(\w+)\s*(?:=|;)/',
    ];

    /**
     * JavaScript allows redeclaring a function, so `node --check` never complains about a duplicate.
     * Functions are keyed by their whole declaration line (same name in another scope is legal).
     */
    private const JS_DECLARATIONS = [
        '/^\s*(?:export\s+)?(?:async\s+)?function\s*\*?\s*[A-Za-z_$][\w$]*\s*\(/',
        '/^\s*(?:[A-Za-z_$][\w$]*|\'[^\']+\'|"[^"]+")\s*:\s*(?:async\s+)?function\b/',
        '/^\s*(?:var|let|const)\s+[A-Za-z_$][\w$]*\s*=\s*(?:async\s+)?(?:function\b|\([^)]*\)\s*=>|[A-Za-z_$][\w$]*\s*=>)/',
        '/^\s*(?:(?:async|static|get|set)\s+)*(?!(?:if|for|while|switch|catch|function|with|return|else)\b)[A-Za-z_$][\w$]*\s*\([^)]*\)\s*\{\s*$/',
    ];

    /** @var array<string, bool> */
    private array $tools = [];
    private int $counter = 0;

    public function __construct(
        private readonly ProcessExecutor $process,
        private readonly string $workDir,
        private readonly bool $strict = false,
    ) {
        (new Filesystem())->ensureDirectoryExists($workDir);
    }

    public function check(Chunk $chunk, Stage $stage): Verdict
    {
        if ($chunk->binary) {
            return Verdict::conflict('binary patches are not supported');
        }
        if ($chunk->copy) {
            return Verdict::conflict('git copy operations are not supported');
        }
        $stage->ensure(...$chunk->paths());
        $patchFile = $this->temp('chunk', '.patch', $chunk->render());

        // 1. Exact change already present → nothing to do. Must run before the forward check:
        //    an add-only hunk often still "applies" a second time somewhere else.
        if ($this->git($stage, ['--check', '-R'], $patchFile)) {
            return Verdict::alreadyApplied();
        }

        // 2. Declarations it adds already exist (added by another patch, or upstream elsewhere).
        $declarations = $this->declarationCheck($chunk, $stage);
        if ($declarations !== null) {
            return $declarations;
        }

        // 3. Must apply; offsets only with unambiguous context.
        $stderr = '';
        if (!$this->git($stage, ['--check', '--verbose'], $patchFile, $stderr)) {
            return Verdict::conflict('does not apply (' . $this->firstError($stderr) . ')');
        }
        $notes = [];
        $current = $chunk->oldPath !== null ? $stage->read($chunk->oldPath) : null;
        foreach ($this->offsets($stderr) as $index => $offset) {
            $hunk = $chunk->hunks[$index - 1] ?? null;
            if ($hunk === null || $current === null) {
                continue;
            }
            $count = $this->countBlock($current, $hunk->oldSide());
            if ($count !== 1) {
                return Verdict::conflict(sprintf(
                    'hunk #%d would apply %+d lines away from its position and its context occurs %d times — ambiguous',
                    $index,
                    $offset,
                    $count
                ));
            }
            $notes[] = sprintf('hunk #%d applies with offset %+d (unique context)', $index, $offset);
        }

        // 4. Apply for real on the staged copy, then lint.
        $before = [];
        foreach ($chunk->paths() as $rel) {
            $before[$rel] = $stage->read($rel);
        }
        if (!$this->git($stage, [], $patchFile, $stderr)) {
            $this->restore($stage, $before);

            return Verdict::conflict('apply failed (' . $this->firstError($stderr) . ')');
        }
        if ($chunk->newPath !== null) {
            $lint = $this->lintRegression($chunk->newPath, $stage->path($chunk->newPath), $before[$chunk->oldPath ?? ''] ?? null, $chunk->isNewFile());
            if ($lint !== null) {
                $this->restore($stage, $before);

                return Verdict::conflict($lint);
            }
        }

        return Verdict::apply($notes);
    }

    /** Is the exact change of this chunk already in the stage? (layer 1 alone) */
    public function isApplied(Chunk $chunk, Stage $stage): bool
    {
        $stage->ensure(...$chunk->paths());

        return $this->git($stage, ['--check', '-R'], $this->temp('applied', '.patch', $chunk->render()));
    }

    /** Take an applied chunk back out of the stage (used to verify a whole chain in reverse order). */
    public function revert(Chunk $chunk, Stage $stage): bool
    {
        $stage->ensure(...$chunk->paths());
        $patchFile = $this->temp('revert', '.patch', $chunk->render());

        return $this->git($stage, ['--check', '-R'], $patchFile) && $this->git($stage, ['-R'], $patchFile);
    }

    private function declarationCheck(Chunk $chunk, Stage $stage): ?Verdict
    {
        $language = match (strtolower(pathinfo($chunk->target(), PATHINFO_EXTENSION))) {
            'php', 'phtml' => 'php',
            'js' => 'js',
            default => null,
        };
        if ($language === null || $chunk->isNewFile() || $chunk->oldPath === null) {
            return null;
        }
        $content = $stage->read($chunk->oldPath);
        if ($content === null) {
            return null;
        }
        $added = $removed = [];
        foreach ($chunk->hunks as $hunk) {
            $added = array_merge($added, $this->declarations($hunk->added(), $language));
            $removed = array_merge($removed, $this->declarations($hunk->removed(), $language));
        }
        $new = array_values(array_unique(array_diff($added, $removed)));
        if ($new === []) {
            return null;
        }
        $existing = $this->declarations(preg_split('/\R/', $content) ?: [], $language);
        $present = array_values(array_intersect($new, $existing));
        if ($present === []) {
            return null;
        }
        $list = implode(', ', array_map([$this, 'label'], $present));
        if (count($present) === count($new) && !$this->strict) {
            return Verdict::alreadyPresent('declares only what already exists: ' . $list);
        }

        return Verdict::conflict(sprintf(
            'would declare again what already exists: %s%s',
            $list,
            count($present) === count($new) ? ' (--strict)' : sprintf(' (%d of %d declarations present — partially applied elsewhere?)', count($present), count($new))
        ));
    }

    /**
     * @param string[] $lines
     * @return string[] keys like "const:SMTP_SCHEME", "function:gettransport", "use:esmtptransportfactory", "js:function init(config) {"
     */
    private function declarations(array $lines, string $language = 'php'): array
    {
        $keys = [];
        if ($language === 'js') {
            foreach ($lines as $line) {
                foreach (self::JS_DECLARATIONS as $regex) {
                    if (preg_match($regex, $line)) {
                        $keys[] = 'js:' . preg_replace('/\s+/', ' ', trim($line));
                        break;
                    }
                }
            }

            return $keys;
        }
        foreach ($lines as $line) {
            foreach (self::DECLARATIONS as $type => $regex) {
                if (!preg_match($regex, $line, $m)) {
                    continue;
                }
                $keys[] = match ($type) {
                    'use' => 'use:' . strtolower($m[2] ?? '' ?: substr((string) strrchr('\\' . $m[1], '\\'), 1)),
                    'function', 'class' => $type . ':' . strtolower($m[1]),
                    default => $type . ':' . $m[1],
                };
                break;
            }
        }

        return $keys;
    }

    private function label(string $key): string
    {
        [$type, $name] = explode(':', $key, 2);

        return match ($type) {
            'js' => '"' . $name . '"',
            'function' => $name . '()',
            'property' => '$' . $name,
            'use' => 'use …\\' . $name,
            default => $type . ' ' . $name,
        };
    }

    /** @param string[] $args */
    private function git(Stage $stage, array $args, string $patchFile, ?string &$stderr = null): bool
    {
        $out = '';
        $code = $this->process->execute(array_merge(['git', '-C', $stage->dir, 'apply', '-p1'], $args, [$patchFile]), $out);
        $stderr = $this->process->getErrorOutput();
        // git may "succeed" while silently skipping files (see composer-patches #165).
        return $code === 0 && !str_starts_with(ltrim($stderr), 'Skipped');
    }

    /** @return array<int, int> hunk number => offset */
    private function offsets(string $stderr): array
    {
        $offsets = [];
        if (preg_match_all('/Hunk #(\d+) succeeded at \d+ \(offset (-?\d+) lines?\)/', $stderr, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $offsets[(int) $match[1]] = (int) $match[2];
            }
        }

        return $offsets;
    }

    /** @param string[] $block */
    private function countBlock(string $content, array $block): int
    {
        if ($block === []) {
            return 0;
        }
        $lines = array_map(static fn (string $l): string => rtrim($l, "\r"), explode("\n", $content));
        $count = 0;
        $size = count($block);
        for ($i = 0, $max = count($lines) - $size; $i <= $max; $i++) {
            if ($lines[$i] === $block[0] && array_slice($lines, $i, $size) === $block) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<string, ?string> $before */
    private function restore(Stage $stage, array $before): void
    {
        foreach ($before as $rel => $content) {
            $stage->write($rel, $content);
        }
    }

    /**
     * Lint the patched file; only a regression counts (a file that did not lint before is not blamed).
     */
    private function lintRegression(string $rel, string $patchedFile, ?string $originalContent, bool $isNew): ?string
    {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $linter = match ($ext) {
            'php', 'phtml' => [PHP_BINARY, '-n', '-l'],
            'xml', 'xsd', 'xsl' => $this->has('xmllint') ? ['xmllint', '--noout'] : null,
            'js' => !$isNew && $this->has('node') ? ['node', '--check'] : null,
            'json' => 'json',
            default => null,
        };
        if ($linter === null || !is_file($patchedFile)) {
            return null;
        }
        $error = $this->lint($linter, $patchedFile);
        if ($error === null) {
            return null;
        }
        if (!$isNew && $originalContent !== null) {
            $original = $this->temp('orig', '.' . $ext, $originalContent);
            if ($this->lint($linter, $original) !== null) {
                return null; // was already broken (or linter cannot judge this file) — not our regression
            }
        }

        return sprintf('patched %s fails lint: %s', $rel, $error);
    }

    /** @param string[]|string $linter */
    private function lint(array|string $linter, string $file): ?string
    {
        if ($linter === 'json') {
            json_decode((string) file_get_contents($file));

            return json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg();
        }
        $out = '';
        if ($this->process->execute(array_merge($linter, [$file]), $out) === 0) {
            return null;
        }
        $message = trim($out . "\n" . $this->process->getErrorOutput());
        foreach (preg_split('/\R/', $message) ?: [] as $line) {
            if (preg_match('/error|fatal|cannot/i', $line)) {
                return trim(str_replace($file, basename($file), $line));
            }
        }

        return 'lint failed';
    }

    private function has(string $tool): bool
    {
        if (!isset($this->tools[$tool])) {
            $out = '';
            $this->tools[$tool] = $this->process->execute([$tool, '--version'], $out) === 0;
        }

        return $this->tools[$tool];
    }

    private function firstError(string $stderr): string
    {
        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            if (str_starts_with($line, 'error:')) {
                return trim(substr($line, 6));
            }
        }

        return trim($stderr) !== '' ? trim(strtok($stderr, "\n")) : 'unknown error';
    }

    private function temp(string $prefix, string $suffix, string $content): string
    {
        $file = sprintf('%s/%s-%d%s', $this->workDir, $prefix, ++$this->counter, $suffix);
        file_put_contents($file, $content);

        return $file;
    }
}
