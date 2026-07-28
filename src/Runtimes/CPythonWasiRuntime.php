<?php

namespace Rushing\Popcorn\Wasm\Runtimes;

use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Wasm\Contracts\WasmRuntime;
use Rushing\Popcorn\Wasm\Support\WasmPlan;

/**
 * CPython-WASI — a **preopened-interpreter-script** runtime (popcorn-runner ticket 08 §5): a prebuilt
 * `python.wasm` runs a guest script mounted via a WASI preopen. Structurally the opposite of Javy
 * (which compiles the user code *into* a module), so shipping both proves the seam spans both shapes
 * and can't secretly become Javy-shaped.
 *
 * **Pure-Python-only and marginal** (thin WASI-wheel ecosystem): *real* Python work — music21/tonal,
 * C-extension wheels — rides bubble's `PythonProvider`. This runtime serves pure-Python transforms +
 * portable/hardened-isolation cases only.
 */
class CPythonWasiRuntime implements WasmRuntime
{
    public function __construct(
        private string $pythonWasm = '',
        private string $guestRoot = '/pkg',
    ) {}

    public function runtimeId(): string
    {
        return 'python-wasi';
    }

    public function supportsNet(): bool
    {
        return false;
    }

    public function engineVersion(): ?string
    {
        return null; // the engine is the fixed python.wasm; no dynamic-link pinning.
    }

    public function probe(bool $needsCompiler): bool
    {
        return $this->pythonWasm !== '' && is_file($this->pythonWasm);
    }

    public function plan(Manifest $manifest, string $bundleRoot, string $cacheDir): WasmPlan
    {
        $guestScript = rtrim($this->guestRoot, '/').'/'.ltrim($manifest->entrypoint, '/');

        return new WasmPlan(
            modulePath: $this->pythonWasm,
            moduleArgs: [$guestScript],
            preopens: [[$bundleRoot, $this->guestRoot, true]],
            cacheKey: is_file($bundleRoot.'/'.$manifest->entrypoint)
                ? (string) md5_file($bundleRoot.'/'.$manifest->entrypoint)
                : md5($manifest->entrypoint),
        );
    }
}
