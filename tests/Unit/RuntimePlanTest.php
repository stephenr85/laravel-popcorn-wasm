<?php

use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Wasm\Runtimes\CPythonWasiRuntime;
use Rushing\Popcorn\Wasm\Runtimes\JavyRuntime;

it('Javy prebuilt runs the shipped .wasm with an engine-pinned cache key (dynamic)', function () {
    $m = Manifest::fromArray(['name' => 't', 'runtime' => 'javy', 'entrypoint' => 'transform.wasm', 'build' => 'prebuilt'])
        ->withBundleRoot('/stage');

    $plan = (new JavyRuntime(engineVersion: 'javy-3'))->plan($m, '/stage', '/cache');

    expect($plan->modulePath)->toBe('/stage/transform.wasm')
        ->and($plan->needsCompile)->toBeFalse()
        ->and($plan->cacheKey)->toStartWith('javy-3-'); // dynamic ⇒ engine-pinned
});

it('Javy static drops the engine coupling from the cache key', function () {
    $m = Manifest::fromArray(['name' => 't', 'runtime' => 'javy', 'entrypoint' => 't.wasm', 'build' => 'prebuilt', 'link' => 'static'])
        ->withBundleRoot('/stage');

    $plan = (new JavyRuntime(engineVersion: 'javy-3'))->plan($m, '/stage', '/cache');

    expect($plan->cacheKey)->not->toStartWith('javy-3-');
});

it('Javy source plans a compile-and-cache-once command', function () {
    $m = Manifest::fromArray(['name' => 't', 'runtime' => 'javy', 'entrypoint' => 'transform.js', 'build' => 'source'])
        ->withBundleRoot('/stage');

    $plan = (new JavyRuntime)->plan($m, '/stage', '/cache');

    expect($plan->needsCompile)->toBeTrue()
        ->and($plan->compileCommand[0])->toBe('javy')
        ->and($plan->compileCommand)->toContain('build')
        ->and($plan->compileCommand)->toContain('/stage/transform.js')
        ->and($plan->modulePath)->toStartWith('/cache/javy-');
});

it('CPython-WASI preopens the bundle and runs the guest script under python.wasm', function () {
    $m = Manifest::fromArray(['name' => 't', 'runtime' => 'python-wasi', 'entrypoint' => 'main.py'])
        ->withBundleRoot('/stage');

    $plan = (new CPythonWasiRuntime(pythonWasm: '/opt/python.wasm'))->plan($m, '/stage', '/cache');

    expect($plan->modulePath)->toBe('/opt/python.wasm')
        ->and($plan->moduleArgs)->toBe(['/pkg/main.py'])
        ->and($plan->preopens)->toBe([['/stage', '/pkg', true]]);
});

it('CPython-WASI probe is false without a configured python.wasm', function () {
    expect((new CPythonWasiRuntime(pythonWasm: ''))->probe(false))->toBeFalse();
});
