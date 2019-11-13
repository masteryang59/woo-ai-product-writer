#!/usr/bin/env node
// Runs PHP (WebAssembly build from @php-wasm/node) with this repo mounted at /app.
// Lets the tests run on machines without a PHP install, on Windows too:
//   node scripts/php.mjs -v
//   node scripts/php.mjs tools/phpunit.phar
// Set PHP_VERSION=8.1 … 8.5 to pick the version (default 8.3).
import { createNodeFsMountHandler, loadNodeRuntime } from '@php-wasm/node';
import { FileLockManagerInMemory, PHP } from '@php-wasm/universal';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const version = process.env.PHP_VERSION || '8.3';

export async function createPhp({ env = {} } = {}) {
  const php = new PHP(
    await loadNodeRuntime(version, {
      fileLockManager: new FileLockManagerInMemory(),
      emscriptenOptions: {
        processId: 1,
        ENV: { ...process.env, ...env, TMPDIR: '/tmp', HOME: '/tmp' },
      },
    }),
  );
  php.mkdirTree('/app');
  php.mount('/app', createNodeFsMountHandler(root));
  php.mkdirTree('/tmp');
  php.chdir('/app');
  return php;
}

export async function runPhp(args, options = {}) {
  const php = await createPhp(options);
  const response = await php.cli([
    'php',
    '-d', 'memory_limit=1024M',
    '-d', 'error_reporting=E_ALL',
    '-d', 'display_errors=stderr',
    ...args,
  ]);
  const pipe = (stream, target) =>
    stream.pipeTo(new WritableStream({ write: (chunk) => void target.write(chunk) }));
  await Promise.all([
    pipe(response.stdout, process.stdout),
    pipe(response.stderr, process.stderr),
  ]);
  try {
    return await response.exitCode;
  } catch (error) {
    if (error?.name === 'ExitStatus') return error.status ?? 1;
    throw error;
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const code = await runPhp(process.argv.slice(2));
  // php-wasm keeps handles open; exit explicitly once output is flushed.
  setTimeout(() => process.exit(code), 50);
}

function archiveRecord(data) {
  if (!data) return null;
  return data;
}