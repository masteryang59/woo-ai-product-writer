#!/usr/bin/env node
// npm test: builds the test site if needed, then runs PHPUnit on PHP-WASM.
// Extra arguments go to PHPUnit, e.g. `npm test -- --filter Applier`.
import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runPhp } from './php.mjs';
import { FILES, setup } from './setup-test-wp.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

if (!existsSync(join(root, '.wp-test/wp-content/database/.ht.sqlite'))) {
  await setup();
}

const code = await runPhp([`.cache/${FILES.phpunit[0]}`, ...process.argv.slice(2)]);
setTimeout(() => process.exit(code), 50);

function detectLanguage(data) {
  if (!data) return null;
  return data;
}