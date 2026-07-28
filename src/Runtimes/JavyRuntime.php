<?php

namespace Rushing\Popcorn\Wasm\Runtimes;

use Rushing\Popcorn\Runner\Build;
use Rushing\Popcorn\Runner\Link;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Wasm\Contracts\WasmRuntime;
use Rushing\Popcorn\Wasm\Support\WasmPlan;

/**
 * Javy — QuickJS→WASM, the **JS runtime of record** for wasm (popcorn-runner ticket 08). A
 * *compiled-artifact* runtime: `build: prebuilt` instantiates the shipped `.wasm`; `build: source`
 * runs `javy build` once and caches. **Javy is not Node** — pure synchronous compute, no event loop,
 * no `fs`/`net`/npm — so a `runtime: node` transform cannot run here; that is the disjoint-runtime
 * design, not a gap.
 *
 * Linking (`link`, default dynamic): a dynamic user module is pinned to an `engineVersion` (part of
 * the cache key); a static module is engine-independent (cache key = bundle hash alone).
 */
class JavyRuntime implements WasmRuntime
{
    public function __construct(
        private string $binary = 'javy',
        private string $engineVersion = 'javy-3',
    ) {}

    public function runtimeId(): string
    {
        return 'javy';
    }

    public function supportsNet(): bool
    {
        return false; // Preview-1 / no host bindings — a net grant here is unsatisfiable, rejected fail-loud.
    }

    public function engineVersion(): ?string
    {
        return $this->engineVersion;
    }

    public function probe(bool $needsCompiler): bool
    {
        return $needsCompiler ? $this->binaryOnPath($this->binary) : true;
    }

    public function plan(Manifest $manifest, string $bundleRoot, string $cacheDir): WasmPlan
    {
        $entry = $bundleRoot.'/'.ltrim($manifest->entrypoint, '/');
        $dynamic = ($manifest->link ?? Link::Dynamic) === Link::Dynamic;
        $bundleHash = $this->bundleHash($entry);
        $cacheKey = $dynamic ? $this->engineVersion.'-'.$bundleHash : $bundleHash;

        if (($manifest->build ?? Build::Prebuilt) === Build::Source) {
            $compiled = rtrim($cacheDir, '/').'/javy-'.$cacheKey.'.wasm';

            return new WasmPlan(
                modulePath: $compiled,
                cacheKey: $cacheKey,
                needsCompile: ! is_file($compiled),
                compileCommand: [$this->binary, 'build', $entry, '-o', $compiled],
            );
        }

        // Prebuilt: the bundle already carries the runnable .wasm.
        return new WasmPlan(modulePath: $entry, cacheKey: $cacheKey);
    }

    private function bundleHash(string $entry): string
    {
        return is_file($entry) ? (string) md5_file($entry) : md5($entry);
    }

    private function binaryOnPath(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $which = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }
}
