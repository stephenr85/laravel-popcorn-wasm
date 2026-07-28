# laravel-popcorn-wasm

A **wasmtime / WASI sandbox substrate** for [popcorn](https://github.com/stephenr85/laravel-popcorn)
Runners — the **portable, dev==prod** route. It implements the popcorn `Runner` contract over
`wasmtime`, with **Javy** (JS→wasm) and **CPython-WASI** runtimes behind a `WasmRuntime` seam. The
kernel stays dependency-free; all wasmtime mechanics live here.

> **Threat frame.** wasm is the **hardest boundary on offer** — an **in-VM boundary**, not a shared
> host kernel with namespaces. It is the escalation target for *genuinely-hostile* code (where bubble
> is defense-in-depth for *semi-trusted* transforms), **and** it runs for-real on macOS with no
> host-sandbox dependency — so a wasm-class transform is **dev==prod on every machine**. Its cost is
> the constrained runtime class (Javy = pure sync compute — no event loop / fs / net; CPython-WASI =
> pure-Python).

## Disjoint runtimes, one seam — no portability fiction

`Manifest.runtime` is a single flat id. Bubble answers to `node`/`python`; wasm answers to its **own**
values (`javy`, `python-wasi`). **Javy is not Node** (no event loop, no `fs`/`net`/npm), so a
`runtime: node` transform simply cannot run here — that is the disjoint-runtime design, not a gap. The
host-capability factory routes `Manifest.runtime` to whichever Runner has a provider, so **an author
picks a runtime and thereby a substrate.**

```php
use Rushing\Popcorn\Wasm\WasmtimeRunner;
use Rushing\Popcorn\Wasm\Runtimes\{JavyRuntime, CPythonWasiRuntime};

$runner = new WasmtimeRunner([new JavyRuntime, new CPythonWasiRuntime(pythonWasm: $path)], config('popcorn-wasm'));
$result = $runner->run($manifest, $effectiveGrant, $input);   // → a total popcorn Result
```

The v1 set is deliberately two *structurally different* runtimes so the seam can't secretly become
one-shaped: Javy (a **compiled-artifact** runtime) and CPython-WASI (a **preopened-interpreter-script**
runtime). CPython-WASI is pure-Python-only and marginal — *real* Python work (music21/tonal, C-ext
wheels) rides bubble's `PythonProvider`.

## Build model — DECLARED, compiled once (never per-invocation)

The Manifest states `build` explicitly (contents-inference is authoring sugar only):

- **`prebuilt`** — the bundle carries the runnable `.wasm`; instantiate only, no host toolchain. Best
  cold-start, deterministic — the natural **published/prod** shape.
- **`source`** — the bundle ships source; the provider compiles-and-caches **once** (Javy: `javy build`),
  keyed by bundle hash. Drop-a-`.js` **authoring** ergonomics — the natural **local-dev** shape.

Javy linking (`link`, default `dynamic`): a dynamic user module is engine-pinned via `engineVersion`
(cache key = `engineVersion + bundleHash`); a resolver mismatch **fails loud** as `SubstrateUnavailable`,
never a silent load. `static` drops the engine coupling (cache key = bundle hash alone) — right for a
widely-distributed published transform that must survive an engine bump.

## Grant unification — the central bet, confirmed

One `Grant` DTO renders onto wasmtime as cleanly as onto bwrap:

| Grant axis | wasmtime flag |
|---|---|
| `paths.ro/rw` | `--dir H::G[::ro]` |
| `env{}` | `--env K=V` |
| `net` (Preview 2) | `-S inherit-network` |
| `limits` | **native in-VM** — `-W max-memory-size=<bytes>` (deterministic, no external cgroup) |
| `seccompProfile` | **declared no-op** — the wasm import surface *is* the syscall allowlist |

`net` on a runtime that can't honor it (Javy / Preview-1) is **unsatisfiable → rejected fail-loud**
(`GrantDenied` on the `net` axis), never silently ignored. Integration is CLI (`wasmtime run`), same
class as bubble's launcher; an in-process warm-`Engine` FFI hot-path is the documented v2 escalation.

## Installation

```bash
composer require rushing/laravel-popcorn-wasm
php artisan vendor:publish --tag=popcorn-wasm-config
```
