<?php

namespace Rushing\Popcorn\Wasm;

use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\Wasm\Runtimes\CPythonWasiRuntime;
use Rushing\Popcorn\Wasm\Runtimes\JavyRuntime;

class WasmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/popcorn-wasm.php', 'popcorn-wasm');

        $this->app->singleton(WasmtimeRunner::class, function ($app) {
            $config = (array) $app['config']->get('popcorn-wasm', []);

            return new WasmtimeRunner(
                runtimes: [
                    new JavyRuntime(
                        binary: $config['javy']['binary'] ?? 'javy',
                        engineVersion: $config['javy']['engine_version'] ?? 'javy-3',
                    ),
                    new CPythonWasiRuntime(
                        pythonWasm: $config['python_wasi']['module'] ?? '',
                    ),
                ],
                config: $config,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/popcorn-wasm.php' => $this->app->configPath('popcorn-wasm.php'),
            ], 'popcorn-wasm-config');
        }
    }
}
