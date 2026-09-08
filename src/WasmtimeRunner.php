<?php

namespace Rushing\Popcorn\Wasm;

use Illuminate\Container\Container;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Runner\Build;
use Rushing\Popcorn\Runner\Concerns\HandlesRunnerIo;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\GrantAxis;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;
use Rushing\Popcorn\Wasm\Contracts\WasmRuntime;
use Rushing\Popcorn\Wasm\Runtimes\CPythonWasiRuntime;
use Rushing\Popcorn\Wasm\Runtimes\JavyRuntime;
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
#[IsRegistry(
    root: 'popcorn.wasm.runtimes',
    entryType: WasmRuntime::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'guest wasm runtimes for the wasmtime substrate, one per `Manifest.runtime` id. Runtime ids are DISJOINT from bubble\'s — there is no portability fiction, an author picks a runtime and thereby a substrate. A later registration of the same id replaces the shipped one. Version-suffixed runtimes (`javy@3`) resolve on the base segment: `@` is not a legal key character, so a suffix never reaches the keyspace.',
)]
class WasmtimeRunner implements Gated, Registry, Runner
{
    use HandlesRunnerIo;

    private const COMPILE_TIMEOUT_SECONDS = 120;

    /** @var array<string, mixed> the defaults every config read falls back through */
    private const CONFIG_DEFAULTS = [
        'wasmtime_binary' => 'wasmtime',
        'default_wall_seconds' => 60,
    ];

    private BasicRegistry $entries;

    /** @var iterable<WasmRuntime>|null the constructor seed, consumed on the first read */
    private ?iterable $seed;

    private bool $seeded = false;

    /**
     * Both `$runtimes` and `$config` default to **null meaning read-through**, not to an empty seed.
     *
     * That is registry-kernel ticket 38's archetype-c rule and it matters more here than in bubble: the
     * two shipped runtimes are themselves BUILT FROM CONFIG (`javy.binary`, `python_wasi.module`), so a
     * constructor that seeded eagerly would freeze host config into the entries — and describing this
     * registry into the index FORCES the singleton to construct at boot, well before a host's own
     * `config()->set()`. An explicit `[]` still means "seed nothing"; only `null` means "the package's
     * own two reference runtimes, built from whatever the config says at first read".
     *
     * @param  iterable<WasmRuntime>|null  $runtimes
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(
        ?iterable $runtimes = null,
        private ?array $config = null,
    ) {
        $this->entries = BasicRegistry::for($this);
        $this->seed = $runtimes;
    }

    /**
     * Register a runtime under its own runtime id.
     *
     * The parameter is WIDENED from {@see Registry::register()} rather than shadowing it —
     * contravariance, so the one-argument self-keying door every historical caller uses keeps working.
     */
    public function register(RegistryKey|string|WasmRuntime $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->ensureSeeded();

        if ($key instanceof WasmRuntime) {
            $entry = $key;
            $key = $key->runtimeId();
        }

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        $this->ensureSeeded();

        return $this->entries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        $this->ensureSeeded();

        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        $this->ensureSeeded();

        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        $this->ensureSeeded();

        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        $this->ensureSeeded();

        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        $this->ensureSeeded();

        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The registered runtime ids, as callers spelled them — {@see keys()} with the declared root
     * stripped back off, because keys go relative in and absolute out.
     *
     * @return string[]
     */
    public function runtimeIds(): array
    {
        $this->ensureSeeded();

        return $this->entries->relativeKeys();
    }

    /**
     * The runtime for a `Manifest.runtime` — the port's own vocabulary, sugar over {@see tryResolve()}.
     * A version suffix (`javy@3`) is stripped first; `@` is not a legal key character, so the full
     * spelling is only ever tried when it happens to parse as one.
     */
    public function runtimeFor(string $runtime): ?WasmRuntime
    {
        $base = strtok($runtime, '@') ?: $runtime;

        /** @var WasmRuntime|null */
        return $this->tryResolve($base)
            ?? ($runtime !== $base && Key::tryParse($runtime) !== null ? $this->tryResolve($runtime) : null);
    }

    /** Seed the constructor's runtimes once, on the first read or write — never in the constructor. */
    private function ensureSeeded(): void
    {
        if ($this->seeded) {
            return;
        }

        // Set BEFORE the loop: register() re-enters here, and the seed must win the race with itself.
        $this->seeded = true;

        $seed = $this->seed ?? $this->shippedRuntimes();
        $this->seed = null;

        foreach ($seed as $runtime) {
            $this->register($runtime);
        }
    }

    /**
     * The two reference runtimes, built from config at seed time rather than at construction.
     *
     * @return list<WasmRuntime>
     */
    private function shippedRuntimes(): array
    {
        $config = $this->config();

        return [
            new JavyRuntime(
                binary: $config['javy']['binary'] ?? 'javy',
                engineVersion: $config['javy']['engine_version'] ?? 'javy-3',
            ),
            new CPythonWasiRuntime(
                pythonWasm: $config['python_wasi']['module'] ?? '',
            ),
        ];
    }

    /**
     * The effective config, read through to the host on every access unless one was injected.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return ($this->config ?? $this->hostConfig())
            + self::CONFIG_DEFAULTS
            + ['cache_dir' => sys_get_temp_dir().'/popcorn-wasm-cache'];
    }

    /** @return array<string, mixed> */
    private function hostConfig(): array
    {
        $container = Container::getInstance();

        return $container->bound('config')
            ? (array) $container->make('config')->get('popcorn-wasm', [])
            : [];
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

        if (! $this->binaryOnPath((string) $this->config()['wasmtime_binary'])) {
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

        $plan = $runtime->plan($manifest, $manifest->bundleRoot, (string) $this->config()['cache_dir']);

        if ($plan->needsCompile && $plan->compileCommand !== null) {
            $build = $this->compile($plan->compileCommand);

            if ($build !== null) {
                return $build; // BuildFailed carrying the compiler stderr
            }
        }

        $argv = (new WasmtimeCommand((string) $this->config()['wasmtime_binary']))->forRun($grant, $plan);

        return $this->execute($argv, $grant, $input);
    }

    /** The wasmtime argv a run *would* assemble — the debugging / release read path (no execution). */
    public function buildRunArgv(Manifest $manifest, Grant $grant): array
    {
        $runtime = $this->runtimeFor($manifest->runtime);

        if ($runtime === null || $manifest->bundleRoot === null) {
            return [];
        }

        $plan = $runtime->plan($manifest, $manifest->bundleRoot, (string) $this->config()['cache_dir']);

        return (new WasmtimeCommand((string) $this->config()['wasmtime_binary']))->forRun($grant, $plan);
    }

    /**
     * Compile source → module once (cached). Returns null on success, or a BuildFailed Result whose
     * error carries the compiler stderr — a build-phase failure distinct from a run-time NonZeroExit.
     *
     * @param  list<string>  $command
     */
    private function compile(array $command): ?Result
    {
        @mkdir((string) $this->config()['cache_dir'], 0700, true);

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
            : (int) $this->config()['default_wall_seconds'];

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
}
