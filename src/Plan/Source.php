<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Plan;

use Brocode\MagentoPatches\Patch\Chunk;
use Brocode\MagentoPatches\Patch\DiffParser;

/**
 * One patch to ingest — an isolated security patch file, a QPT patch, a custom patch,
 * or a stored package patch that is re-validated after an upgrade.
 */
final class Source
{
    /**
     * @param Chunk[] $chunks
     * @param ?string $package set when chunk paths are already relative to this package
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $id,
        public readonly string $detail,
        public readonly string $fileName,
        public readonly array $chunks,
        public readonly ?string $package = null,
        public readonly ?string $label = null,
    ) {
    }

    public static function fromFile(string $tag, string $id, string $detail, string $file, ?string $package = null): self
    {
        $chunks = (new DiffParser())->parse((string) file_get_contents($file));

        return new self($tag, $id, $detail, basename($file), $chunks, $package);
    }

    /** A patch already stored for one package, re-validated under its existing label. */
    public static function stored(string $tag, string $id, string $label, string $package, string $fileName, string $content): self
    {
        return new self($tag, $id, '', $fileName, (new DiffParser())->parse($content), $package, $label);
    }

    public function name(): string
    {
        return $this->id . ($this->fileName !== '' ? ' (' . $this->fileName . ')' : '');
    }
}
