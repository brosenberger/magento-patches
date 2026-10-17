<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Patch;

final class Hunk
{
    /**
     * @param string[] $lines raw hunk body lines including their line endings (" ", "-", "+", "\")
     */
    public function __construct(
        public readonly string $header,
        public readonly array $lines,
        public readonly int $oldStart,
        public readonly int $oldCount,
    ) {
    }

    /** Lines a hunk expects to find in the file: context + removed, without prefix and EOL. */
    public function oldSide(): array
    {
        return $this->collect([' ', '-']);
    }

    /** @return string[] */
    public function added(): array
    {
        return $this->collect(['+']);
    }

    /** @return string[] */
    public function removed(): array
    {
        return $this->collect(['-']);
    }

    /** @return string[] */
    private function collect(array $prefixes): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            if ($line !== '' && in_array($line[0], $prefixes, true)) {
                $out[] = rtrim(substr($line, 1), "\r\n");
            }
        }

        return $out;
    }

    public function render(): string
    {
        $text = $this->header . implode('', $this->lines);

        return str_ends_with($text, "\n") ? $text : $text . "\n";
    }
}
