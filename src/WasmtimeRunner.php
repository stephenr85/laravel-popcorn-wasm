<?php

namespace Rushing\Popcorn\Wasm;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Runner\Build;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\GrantAxis;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;
use Rushing\Popcorn\Wasm\Contracts\WasmRuntime;
use Rushing\Popcorn\Wasm\Support\WasmtimeCommand;

/**
 * The wasmtime {@see Runner} — the **portable, dev==prod** route (popcorn-runner ticket 08). ONE
 * Runner for the backend; the guest runtime rides the {@see WasmRuntime} seam. It runs *for real* on
 * macOS with no host-sandbox dependency (the answer to bubble's Linux-only problem), and is the
 * **hardest boundary on offer** — an in-VM boundary, the escalation target for genuinely-hostile code.
 *
 * Runtime sets are disjoint from bubble; the host-capability factory routes `Manifest.runtime` to
 * whichever Runner has a provider. `Result.sandboxed` is always true — wasm isolation is real
 * everywhere, including in local dev.
 */
class WasmtimeRunner implements Runner
{
    private const OUTPUT_HARD_CAP_BYTES = 262144; // 256 KiB

    private const STDERR_TAIL_BYTES = 16384; // 16 KiB

    private const COMPILE_TIMEOUT_SECONDS = 120;

    /** @var array<string, WasmRuntime> keyed by runtimeId */
    private array $runtimes = [];

    /**
     * @param  iterable<WasmRuntime>  $runtimes
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        iterable $runtimes = [],
        private array $config = [],
    ) {
        foreach ($runtimes as $runtime) {
            $this->register($runtime);
        }

        $this->config += [
            'wasmtime_binary' => 'wasmtime',
            'cache_dir' => sys_get_temp_dir().'/popcorn-wasm-cache',
            'default_wall_seconds' => 60,
        ];
    }

    public function register(WasmRuntime $runtime): static
    {
        $this->runtimes[$runtime->runtimeId()] = $runtime;

        return $this;
    }

    public function runtimeFor(string $runtime): ?WasmRuntime
    {
        $base = strtok($runtime, '@') ?: $runtime;

        return $this->runtimes[$base] ?? $this->runtimes[$runtime] ?? null;
    }

    public function run(Manifest $manifest, Grant $grant, array $input): Result
    {
        $runtime = $this->runtimeFor($manifest->runtime);

        if ($runtime === null) {
            return Result::substrateUnavailable("popcorn-wasm: no WasmRuntime for runtime `{$manifest->runtime}`.");
        }

        if ($manifest->bundleRoot === null) {
            return Result::substrateUnavailable('popcorn-wasm: manifest has no bundleRoot.');
        }

        // net on a runtime that can't honor it is unsatisfiable — fail loud, never silently drop (05).
        if ($grant->net->allowsEgress() && ! $runtime->supportsNet()) {
            return Result::grantDenied(GrantAxis::Net, "net:{$grant->net->value}", "popcorn-wasm: runtime `{$manifest->runtime}` cannot honor a net grant.");
        }

        $needsCompiler = ($manifest->build ?? Build::Prebuilt) === Build::Source;

        if (! $this->binaryOnPath((string) $this->config['wasmtime_binary'])) {
            return Result::substrateUnavailable('popcorn-wasm: wasmtime binary not found on PATH.');
        }

        if (! $runtime->probe($needsCompiler)) {
            return Result::substrateUnavailable("popcorn-wasm: runtime `{$manifest->runtime}` not runnable (missing wasm module or compiler).");
        }

        // Dynamic-prebuilt engine pinning: a declared engineVersion that mismatches fails loud (never a silent load).
        if ($manifest->engineVersion !== null
            && $runtime->engineVersion() !== null
            && $manifest->engineVersion !== $runtime->engineVersion()) {
            return Result::substrateUnavailable("popcorn-wasm: engine mismatch — bundle wants `{$manifest->engineVersion}`, host has `{$runtime->engineVersion()}`.");
        }

        $plan = $runtime->plan($manifest, $manifest->bundleRoot, (string) $this->config['cache_dir']);

        if ($plan->needsCompile && $plan->compileCommand !== null) {
            $build = $this->compile($plan->compileCommand);

            if ($build !== null) {
                return $build; // BuildFailed carrying the compiler stderr
            }
        }

        $argv = (new WasmtimeCommand((string) $this->config['wasmtime_binary']))->forRun($grant, $plan);

        return $this->execute($argv, $grant, $input);
    }

    /** The wasmtime argv a run *would* assemble — the debugging / release read path (no execution). */
    public function buildRunArgv(Manifest $manifest, Grant $grant): array
    {
        $runtime = $this->runtimeFor($manifest->runtime);

        if ($runtime === null || $manifest->bundleRoot === null) {
            return [];
        }

        $plan = $runtime->plan($manifest, $manifest->bundleRoot, (string) $this->config['cache_dir']);

        return (new WasmtimeCommand((string) $this->config['wasmtime_binary']))->forRun($grant, $plan);
    }

    /**
     * Compile source → module once (cached). Returns null on success, or a BuildFailed Result whose
     * error carries the compiler stderr — a build-phase failure distinct from a run-time NonZeroExit.
     *
     * @param  list<string>  $command
     */
    private function compile(array $command): ?Result
    {
        @mkdir((string) $this->config['cache_dir'], 0700, true);

        $proc = Process::timeout(self::COMPILE_TIMEOUT_SECONDS)->run($command);

        if ($proc->failed()) {
            return new Result(
                Outcome::BuildFailed,
                stderr: $this->tail($proc->errorOutput(), self::STDERR_TAIL_BYTES),
                error: trim($proc->errorOutput()) ?: 'popcorn-wasm: build failed.',
            );
        }

        return null;
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, mixed>  $input
     */
    private function execute(array $argv, Grant $grant, array $input): Result
    {
        try {
            $payload = json_encode(['input' => $input, 'grant' => $grant->toArray()], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new Result(Outcome::MalformedOutput, error: "popcorn-wasm: cannot encode input: {$e->getMessage()}");
        }

        $timeoutSeconds = $grant->limits->wallMs !== null
            ? (int) max(1, ceil($grant->limits->wallMs / 1000))
            : (int) $this->config['default_wall_seconds'];

        $startedAt = microtime(true);

        try {
            $proc = Process::input($payload)->timeout($timeoutSeconds)->run($argv);
        } catch (ProcessTimedOutException) {
            return new Result(
                Outcome::Timeout,
                error: "popcorn-wasm: run timed out after {$timeoutSeconds}s.",
                wallMs: (int) round((microtime(true) - $startedAt) * 1000),
                limitHit: true,
            );
        }

        $wallMs = (int) round((microtime(true) - $startedAt) * 1000);
        $stderr = $this->tail($proc->errorOutput(), self::STDERR_TAIL_BYTES);
        $stderrTruncated = strlen($proc->errorOutput()) > self::STDERR_TAIL_BYTES;

        if ($proc->failed()) {
            return new Result(
                Outcome::NonZeroExit,
                stderr: $stderr,
                stderrTruncated: $stderrTruncated,
                error: trim($proc->errorOutput()) ?: "popcorn-wasm: module exited {$proc->exitCode()}.",
                wallMs: $wallMs,
                exitCode: $proc->exitCode(),
            );
        }

        $raw = $proc->output();

        if (strlen($raw) > self::OUTPUT_HARD_CAP_BYTES || ! $this->isJsonObject(trim($raw))) {
            return new Result(
                Outcome::MalformedOutput,
                rawOutput: strlen($raw) > self::OUTPUT_HARD_CAP_BYTES ? '' : $raw,
                stderr: $stderr,
                stderrTruncated: $stderrTruncated,
                error: 'popcorn-wasm: output exceeded the hard cap or was not a JSON object.',
                wallMs: $wallMs,
                exitCode: $proc->exitCode(),
            );
        }

        return new Result(
            Outcome::Success,
            rawOutput: $raw,
            stderr: $stderr,
            stderrTruncated: $stderrTruncated,
            wallMs: $wallMs,
            exitCode: $proc->exitCode(),
        );
    }

    private function isJsonObject(string $candidate): bool
    {
        try {
            return is_array(json_decode($candidate, true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return false;
        }
    }

    private function tail(string $value, int $bytes): string
    {
        return strlen($value) > $bytes ? substr($value, -$bytes) : $value;
    }

    private function binaryOnPath(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $which = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }
}
