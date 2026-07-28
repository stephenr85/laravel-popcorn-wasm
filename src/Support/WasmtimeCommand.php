<?php

namespace Rushing\Popcorn\Wasm\Support;

use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Net;

/**
 * Pure assembly of the `wasmtime run <flags> <module> <args>` argv (popcorn-runner ticket 08). The
 * central bet — one Grant DTO renders onto wasmtime as cleanly as onto bwrap — lives here, confirmed
 * by research 03:
 *
 *   paths.ro/rw → --dir H::G[::ro]   env → --env K=V   net → -S inherit-network (Preview 2)
 *   limits → NATIVE in-VM: -W max-memory-size=<bytes>, --fuel (vs bubble's ride-around)
 *   seccompProfile → declared no-op (the wasm import surface IS the syscall allowlist)
 *
 * No I/O, no execution — deterministic, so the whole flag surface is unit tested on any OS.
 */
class WasmtimeCommand
{
    public function __construct(
        private string $wasmtimeBinary = 'wasmtime',
    ) {}

    /**
     * @return list<string>
     */
    public function forRun(Grant $grant, WasmPlan $plan): array
    {
        return [
            $this->wasmtimeBinary,
            'run',
            ...$this->limitFlags($grant),
            ...$this->netFlags($grant),
            ...$this->preopenFlags($plan),
            ...$this->dirFlags($grant),
            ...$this->envFlags($grant),
            $plan->modulePath,
            ...$plan->moduleArgs,
        ];
    }

    /** @return list<string> */
    private function limitFlags(Grant $grant): array
    {
        $flags = [];

        if ($grant->limits->memBytes !== null) {
            $flags[] = '-W';
            $flags[] = 'max-memory-size='.$grant->limits->memBytes;
        }

        return $flags;
    }

    /** @return list<string> */
    private function netFlags(Grant $grant): array
    {
        // The runner rejects net on a non-supporting runtime *before* here (fail-loud); by the time
        // we assemble flags, egress is honored via Preview-2 inherit-network.
        return $grant->net !== Net::None && $grant->net->allowsEgress()
            ? ['-S', 'inherit-network']
            : [];
    }

    /** @return list<string> */
    private function preopenFlags(WasmPlan $plan): array
    {
        $flags = [];

        foreach ($plan->preopens as [$host, $guest, $ro]) {
            $flags[] = '--dir';
            $flags[] = $host.'::'.$guest.($ro ? '::ro' : '');
        }

        return $flags;
    }

    /** @return list<string> */
    private function dirFlags(Grant $grant): array
    {
        $flags = [];

        foreach ($grant->pathsRo as $path) {
            $flags[] = '--dir';
            $flags[] = $path.'::'.$path.'::ro';
        }

        foreach ($grant->pathsRw as $path) {
            $flags[] = '--dir';
            $flags[] = $path.'::'.$path;
        }

        return $flags;
    }

    /** @return list<string> */
    private function envFlags(Grant $grant): array
    {
        $flags = [];

        foreach ($grant->env as $key => $value) {
            $flags[] = '--env';
            $flags[] = $key.'='.$value;
        }

        return $flags;
    }
}
