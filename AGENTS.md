> You are in **rushing/laravel-popcorn-wasm** — a wasmtime/WASI sandbox substrate for popcorn Runners, the portable dev==prod route (Javy JS→wasm and CPython-WASI runtimes behind a WasmRuntime seam).

This is a leaf Composer package implementing the `popcorn` `Runner` contract over `wasmtime`. It vendors `rushing/laravel-popcorn` (path-repo symlinked from `../laravel-popcorn`) for the kernel contracts it runs against; wasmtime mechanics live entirely in this repo.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
