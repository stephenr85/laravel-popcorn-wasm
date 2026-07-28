<?php

namespace Rushing\Popcorn\Wasm\Support;

use Rushing\Popcorn\Wasm\Contracts\WasmRuntime;
use Rushing\Popcorn\Wasm\WasmtimeRunner;

/**
 * A {@see WasmRuntime}'s resolved run plan (popcorn-runner ticket 08).
 * Immutable data the {@see WasmtimeRunner} turns into a `wasmtime run` argv.
 */
class WasmPlan
{
    /**
     * @param  string  $modulePath  host path of the `.wasm` to run (the compiled artifact, for source mode)
     * @param  list<string>  $moduleArgs  args after the module (e.g. the guest script path for python-wasi)
     * @param  list<array{0: string, 1: string, 2: bool}>  $preopens  [hostDir, guestDir, ro] the runtime itself needs
     * @param  ?list<string>  $compileCommand  argv that compiles source → modulePath (source mode); null when prebuilt
     */
    public function __construct(
        public string $modulePath,
        public array $moduleArgs = [],
        public array $preopens = [],
        public string $cacheKey = '',
        public bool $needsCompile = false,
        public ?array $compileCommand = null,
    ) {}
}
