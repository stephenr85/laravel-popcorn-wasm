<?php

use Illuminate\Support\Facades\Process;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Net;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Wasm\Contracts\WasmRuntime;
use Rushing\Popcorn\Wasm\Runtimes\JavyRuntime;
use Rushing\Popcorn\Wasm\Support\WasmPlan;
use Rushing\Popcorn\Wasm\WasmtimeRunner;

/** Use `php` as a stand-in wasmtime binary so binaryOnPath() passes; Process::fake intercepts the run. */
function wasmRunner(array $runtimes, array $config = []): WasmtimeRunner
{
    return new WasmtimeRunner($runtimes, array_merge(['wasmtime_binary' => 'php'], $config));
}

function javyManifest(array $overrides = []): Manifest
{
    return Manifest::fromArray(array_merge([
        'name' => 't', 'runtime' => 'javy', 'entrypoint' => 'transform.wasm', 'build' => 'prebuilt',
    ], $overrides))->withBundleRoot(sys_get_temp_dir());
}

it('is unavailable for a runtime with no provider', function () {
    $r = wasmRunner([new JavyRuntime])->run(javyManifest(['runtime' => 'ruby']), Grant::none(), []);
    expect($r->outcome)->toBe(Outcome::SubstrateUnavailable);
});

it('rejects a net grant on a runtime that cannot honor it — fail-loud GrantDenied, never silent', function () {
    Process::fake();
    $r = wasmRunner([new JavyRuntime])->run(javyManifest(), new Grant(net: Net::Open), []);

    expect($r->outcome)->toBe(Outcome::GrantDenied)
        ->and($r->deniedAxis->value)->toBe('net');
    Process::assertNothingRan();
});

it('fails loud on a dynamic-prebuilt engineVersion mismatch', function () {
    Process::fake();
    $m = javyManifest(['engineVersion' => 'javy-99']);
    $r = wasmRunner([new JavyRuntime(engineVersion: 'javy-3')])->run($m, Grant::none(), []);

    expect($r->outcome)->toBe(Outcome::SubstrateUnavailable);
    Process::assertNothingRan();
});

it('runs the module and decodes JSON stdout — sandboxed is true even in dev', function () {
    Process::fake(['*' => Process::result(output: json_encode(['ok' => true]))]);

    $r = wasmRunner([new JavyRuntime])->run(javyManifest(), Grant::none(), ['x' => 1]);

    expect($r->outcome)->toBe(Outcome::Success)
        ->and($r->output())->toBe(['ok' => true])
        ->and($r->sandboxed)->toBeTrue();
});

it('reflects the effective grant in-band as the {input, grant} envelope', function () {
    Process::fake(['*' => Process::result(output: '{}')]);

    wasmRunner([new JavyRuntime])->run(javyManifest(), Grant::none(), ['q' => 7]);

    Process::assertRan(fn ($p) => json_decode($p->input, true)['input'] === ['q' => 7]);
});

it('maps a compile failure to BuildFailed carrying the compiler stderr (source mode)', function () {
    // A runtime that reports needing a one-shot compile with a command we make fail.
    $compiling = new class implements WasmRuntime
    {
        public function runtimeId(): string
        {
            return 'javy';
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

        public function plan($manifest, $bundleRoot, $cacheDir): WasmPlan
        {
            return new WasmPlan(modulePath: '/cache/x.wasm', needsCompile: true, compileCommand: ['javy', 'build', 'bad.js']);
        }
    };

    Process::fake(['*' => Process::result(output: '', errorOutput: 'SyntaxError: unexpected token', exitCode: 1)]);

    $r = wasmRunner([$compiling])->run(javyManifest(['build' => 'source']), Grant::none(), []);

    expect($r->outcome)->toBe(Outcome::BuildFailed)->and($r->error)->toContain('SyntaxError');
});

it('assembles a wasmtime argv for the debugging read path without executing', function () {
    Process::fake();
    $argv = wasmRunner([new JavyRuntime])->buildRunArgv(javyManifest(), Grant::none());

    expect($argv[0])->toBe('php')->and($argv[1])->toBe('run');
    Process::assertNothingRan();
});
