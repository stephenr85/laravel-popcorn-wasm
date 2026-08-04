<?php

namespace Rushing\Popcorn\Wasm\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Popcorn\Wasm\WasmServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, WasmServiceProvider::class];
    }
}
