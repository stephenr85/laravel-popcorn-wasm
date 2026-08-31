<?php

namespace Rushing\Popcorn\Wasm;

use Illuminate\Support\ServiceProvider;

class WasmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/popcorn-wasm.php', 'popcorn-wasm');

        // Neither the runtime list nor the config is passed in: the runner reads both through, so
        // describing it below cannot freeze either at boot (registry-kernel 38, archetype c). The two
        // shipped runtimes are built from config *at seed time*, inside the runner.
        $this->app->singleton(WasmtimeRunner::class, fn () => new WasmtimeRunner);
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
