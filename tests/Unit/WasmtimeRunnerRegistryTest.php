<?php

use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Wasm\Contracts\WasmRuntime;
use Rushing\Popcorn\Wasm\Support\WasmPlan;
use Rushing\Popcorn\Wasm\WasmtimeRunner;

class FakeSpinRuntime implements WasmRuntime
{
    public function runtimeId(): string
    {
        return 'spin';
    }

    public function supportsNet(): bool
    {
        return false;
    }

    public function engineVersion(): ?string
    {
        return null;
    }

    public function probe(bool $needsCompiler): bool
    {
        return true;
    }

    public function plan(Manifest $manifest, string $bundleRoot, string $cacheDir): WasmPlan
    {
        return new WasmPlan(modulePath: $bundleRoot.'/spin.wasm', cacheKey: 'spin');
    }
}

/**
 * The harness tripwire (registry-kernel 27 D3): a package whose testbench harness does not boot
 * `PopcornServiceProvider` gets an auto-resolvable but UNSHARED `RegistryIndex`, so every
 * `describe()` lands on a throwaway and every index assertion below passes over an empty index.
 */
it('shares one RegistryIndex across the container', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('conforms to the registry contract', function () {
    expect(app(WasmtimeRunner::class))
        ->toBeInstanceOf(Registry::class)
        ->toBeInstanceOf(Gated::class);
});

it('is described into the index at its declared root after boot', function () {
    $index = app(RegistryIndex::class);

    expect($index->has('popcorn.wasm.runtimes'))->toBeTrue()
        ->and($index->resolve('popcorn.wasm.runtimes'))->toBe(app(WasmtimeRunner::class));
});

it('seeds the two in-package reference runtimes read-through, not at construction', function () {
    expect(app(WasmtimeRunner::class)->runtimeIds())->toBe(['javy', 'python-wasi']);
});

it('round-trips a registration through the port vocabulary', function () {
    $runner = app(WasmtimeRunner::class);

    $runner->register(new FakeSpinRuntime);

    expect($runner->runtimeFor('spin'))->toBeInstanceOf(FakeSpinRuntime::class)
        ->and($runner->runtimeFor('spin@2'))->toBeInstanceOf(FakeSpinRuntime::class)
        ->and($runner->has('spin'))->toBeTrue()
        ->and($runner->resolve('popcorn.wasm.runtimes.spin'))->toBeInstanceOf(FakeSpinRuntime::class)
        ->and($runner->runtimeIds())->toContain('spin');
});

it('lets a later registration of the same runtime supersede the shipped one', function () {
    $runner = new WasmtimeRunner;

    $runner->register('javy', new FakeSpinRuntime);

    // Superseding APPENDS rather than assigning in place, so `javy` moves to the end of registration
    // order where the old PHP-array assignment held its slot. Nothing reads this registry in order —
    // `runtimeFor()` is a PickOne lookup — so the move is observable only through `runtimeIds()`.
    expect($runner->runtimeFor('javy'))->toBeInstanceOf(FakeSpinRuntime::class)
        ->and($runner->runtimeIds())->toBe(['python-wasi', 'javy']);
});

it('builds the shipped runtimes from config set AFTER the container booted them', function () {
    // describe() has already forced the singleton to construct; the entries must still be unseeded.
    $runner = app(WasmtimeRunner::class);

    config()->set('popcorn-wasm.javy.engine_version', 'javy-from-a-later-config-set');

    expect($runner->runtimeFor('javy')->engineVersion())->toBe('javy-from-a-later-config-set');
});

it('reads the wasmtime binary through to the host rather than snapshotting it', function () {
    $runner = app(WasmtimeRunner::class);

    config()->set('popcorn-wasm.wasmtime_binary', 'wasmtime-from-a-later-config-set');

    $manifest = Manifest::fromArray(['name' => 't', 'runtime' => 'javy', 'entrypoint' => 'm.wasm'])
        ->withBundleRoot(sys_get_temp_dir());

    expect($runner->buildRunArgv($manifest, Rushing\Popcorn\Runner\Grant::none())[0])
        ->toBe('wasmtime-from-a-later-config-set');
});
