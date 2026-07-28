<?php

use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Limits;
use Rushing\Popcorn\Runner\Net;
use Rushing\Popcorn\Wasm\Support\WasmPlan;
use Rushing\Popcorn\Wasm\Support\WasmtimeCommand;

it('renders paths, env and net onto wasmtime flags (the unified Grant bet)', function () {
    $grant = new Grant(
        pathsRo: ['/data'],
        pathsRw: ['/scratch'],
        net: Net::Open,
        env: ['API' => 'x'],
    );
    $plan = new WasmPlan(modulePath: '/cache/mod.wasm');

    $line = implode(' ', (new WasmtimeCommand)->forRun($grant, $plan));

    expect($line)->toContain('--dir /data::/data::ro')
        ->toContain('--dir /scratch::/scratch')
        ->toContain('--env API=x')
        ->toContain('-S inherit-network')
        ->toContain('/cache/mod.wasm');
});

it('maps memory limits to a native in-VM flag', function () {
    $line = implode(' ', (new WasmtimeCommand)->forRun(new Grant(limits: new Limits(memBytes: 1048576)), new WasmPlan(modulePath: 'm.wasm')));

    expect($line)->toContain('-W max-memory-size=1048576');
});

it('emits the runtime preopens and appends module args (python-wasi shape)', function () {
    $plan = new WasmPlan(
        modulePath: '/opt/python.wasm',
        moduleArgs: ['/pkg/main.py'],
        preopens: [['/stage/bundle', '/pkg', true]],
    );

    $argv = (new WasmtimeCommand)->forRun(Grant::none(), $plan);

    expect(implode(' ', $argv))->toContain('--dir /stage/bundle::/pkg::ro');
    expect(array_slice($argv, -2))->toBe(['/opt/python.wasm', '/pkg/main.py']);
});

it('leaves net off on the floor grant', function () {
    $line = implode(' ', (new WasmtimeCommand)->forRun(Grant::none(), new WasmPlan(modulePath: 'm.wasm')));
    expect($line)->not->toContain('inherit-network');
});
