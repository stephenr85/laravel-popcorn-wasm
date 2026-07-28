<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The wasmtime binary
    |--------------------------------------------------------------------------
    */
    'wasmtime_binary' => env('POPCORN_WASM_WASMTIME', 'wasmtime'),

    /*
    |--------------------------------------------------------------------------
    | Module / compilation cache (MANDATORY)
    |--------------------------------------------------------------------------
    | Where `build: "source"` compiled artifacts are cached (keyed by bundle hash,
    | + engineVersion for dynamic linking). Compilation happens ONCE, never per
    | invocation. See ticket 08 §3.
    */
    'cache_dir' => env('POPCORN_WASM_CACHE', sys_get_temp_dir().'/popcorn-wasm-cache'),

    /*
    |--------------------------------------------------------------------------
    | Javy runtime
    |--------------------------------------------------------------------------
    | The javy compiler binary (source-mode only) and the engine version a
    | dynamic-prebuilt user module is pinned to. A resolver mismatch fails loud
    | as SubstrateUnavailable — never a silent load.
    */
    'javy' => [
        'binary' => env('POPCORN_WASM_JAVY', 'javy'),
        'engine_version' => env('POPCORN_WASM_JAVY_ENGINE', 'javy-3'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CPython-WASI runtime
    |--------------------------------------------------------------------------
    | Path to the prebuilt python.wasm (pure-Python-only; real Python work rides
    | bubble's PythonProvider). Empty ⇒ the runtime probe() returns false.
    */
    'python_wasi' => [
        'module' => env('POPCORN_WASM_PYTHON', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default wall-time ceiling (seconds)
    |--------------------------------------------------------------------------
    */
    'default_wall_seconds' => 60,

];
