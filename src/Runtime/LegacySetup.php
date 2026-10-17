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

namespace BroCode\MagentoPatches\Runtime;

use Composer\Package\RootPackageInterface;

/**
 * Patch mechanisms from before this plugin that keep changing vendor/ behind composer-patches 2.x,
 * or that 2.x silently ignores. Each finding is one line for `patches:status` and the mutating commands.
 */
final class LegacySetup
{
    private const SCRIPT_PATTERNS = [
        '/(^|[\s;&|(])patch\s+-/' => 'runs `patch`',
        '/\bgit\s+apply\b/' => 'runs `git apply`',
        '/\bmagento-patches\s+apply\b/' => 'applies Quality Patches with the QPT tool',
        '/\bece-patches\s+apply\b/' => 'applies patches with ece-tools',
    ];

    /** @return string[] */
    public static function findings(RootPackageInterface $root): array
    {
        $extra = $root->getExtra();
        $findings = [];
        if (isset($extra['patches-file']) && !isset($extra['composer-patches']['patches-file'])) {
            $findings[] = sprintf(
                'extra.patches-file ("%s") is the composer-patches 1.x key — 2.x ignores it, so those patches are NOT applied. Move it to extra.composer-patches.patches-file.',
                is_string($extra['patches-file']) ? $extra['patches-file'] : '…'
            );
        }
        foreach ($root->getScripts() as $event => $commands) {
            foreach ((array) $commands as $command) {
                foreach (self::SCRIPT_PATTERNS as $pattern => $what) {
                    if (is_string($command) && preg_match($pattern, $command)) {
                        $findings[] = sprintf('script "%s" %s outside composer-patches: %s', $event, $what, self::shorten($command));
                        break;
                    }
                }
            }
        }

        return $findings;
    }

    private static function shorten(string $command): string
    {
        return mb_strlen($command) > 100 ? mb_substr($command, 0, 97) . '…' : $command;
    }
}
