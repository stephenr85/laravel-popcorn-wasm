<?php

namespace Rushing\Popcorn\Wasm\Contracts;

use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Wasm\Runtimes\CPythonWasiRuntime;
use Rushing\Popcorn\Wasm\Runtimes\JavyRuntime;
use Rushing\Popcorn\Wasm\Support\WasmPlan;

/**
 * The wasm-package-local extension seam (popcorn-runner ticket 08 §3) — parallel to, but distinct
 * from, bubble's `LanguageProvider`, because a guest `.wasm` module is not a host-binary argv. A
 * runtime's job is to assemble module(s) + preopens + args and key the module cache (maybe compile),
 * which does not fit an argv-shaped hole.
 *
 * The v1 set is deliberately two *structurally different* runtimes so the seam can't secretly become
 * one-runtime-shaped: {@see JavyRuntime} (a compiled-artifact runtime,
 * `build: source` runs the compiler) and {@see CPythonWasiRuntime} (a
 * preopened-interpreter-script runtime). Runtime sets are **disjoint** from bubble — there is no
 * portability fiction; an author picks a runtime and thereby a substrate.
 */
interface WasmRuntime
{
    /** The flat `Manifest.runtime` id this runtime answers to (`"javy"`, `"python-wasi"`). */
    public function runtimeId(): string;

    /**
     * Whether this runtime can honor a `net` grant. Javy / WASI-Preview-1 cannot — a net grant on
     * such a runtime is unsatisfiable and rejected fail-loud (05's silent-drop ban), never ignored.
     */
    public function supportsNet(): bool;

    /**
     * The engine version a dynamic-prebuilt user module is pinned to (part of the cache key); null
     * when linking is engine-independent (static / not applicable). A resolver mismatch fails loud.
     */
    public function engineVersion(): ?string;

    /** Is this runtime runnable? Prebuilt needs only wasmtime; `$needsCompiler` also probes the toolchain. */
    public function probe(bool $needsCompiler): bool;

    /**
     * Resolve the run plan: which module executes, its args, the preopens the runtime itself needs,
     * the cache key, and — for `build: source` — how to compile-and-cache (once, never per-invocation).
     */
    public function plan(Manifest $manifest, string $bundleRoot, string $cacheDir): WasmPlan;
}
