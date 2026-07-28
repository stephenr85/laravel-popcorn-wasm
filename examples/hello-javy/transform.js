// The reference popcorn-wasm Javy transform. Javy is QuickJS→WASM: pure synchronous
// compute, no event loop, no fs/net. Read the JSON envelope { input, grant } from stdin
// (Javy's Javy.IO), write one JSON object to stdout.

const stdin = new Uint8Array(1024 * 64);
let read = 0;
while (true) {
  const n = Javy.IO.readSync(0, stdin.subarray(read));
  if (n === 0) break;
  read += n;
}

const { input, grant } = JSON.parse(new TextDecoder().decode(stdin.subarray(0, read)) || '{}');

const name = (input && input.name) || 'world';
const out = new TextEncoder().encode(JSON.stringify({ greeting: `hello, ${name}`, net: grant?.net ?? 'none' }));

Javy.IO.writeSync(1, out);
