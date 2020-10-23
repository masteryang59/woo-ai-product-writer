#!/usr/bin/env node
// Downloads WordPress, WooCommerce, the SQLite drop-in and PHPUnit into .cache/ and
// installs the test site into .wp-test/. Safe to re-run; `--fresh` rebuilds the site.
import { createWriteStream, existsSync, mkdirSync, rmSync, statSync } from 'node:fs';
import { rename } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { fileURLToPath } from 'node:url';
import { runPhp } from './php.mjs';

export const VERSIONS = {
  wordpress: '7.1.4',
  woocommerce: '11.2.1',
  sqlite: '3.1.2',
  phpunit: '11.5.57',
};

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const cache = join(root, '.cache');
const site = join(root, '.wp-test');

export const FILES = {
  wordpress: [`wordpress-${VERSIONS.wordpress}.zip`, `https://downloads.wordpress.org/release/wordpress-${VERSIONS.wordpress}.zip`],
  woocommerce: [`woocommerce.${VERSIONS.woocommerce}.zip`, `https://downloads.wordpress.org/plugin/woocommerce.${VERSIONS.woocommerce}.zip`],
  sqlite: [`sqlite-database-integration.${VERSIONS.sqlite}.zip`, `https://downloads.wordpress.org/plugin/sqlite-database-integration.${VERSIONS.sqlite}.zip`],
  phpunit: [`phpunit-${VERSIONS.phpunit}.phar`, `https://phar.phpunit.de/phpunit-${VERSIONS.phpunit}.phar`],
};

async function download(file, url, tries = 6) {
  const target = join(cache, file);
  if (existsSync(target) && statSync(target).size > 0) return target;
  for (let attempt = 1; attempt <= tries; attempt++) {
    try {
      process.stdout.write(`Downloading ${url}… `);
      const response = await fetch(url, { redirect: 'follow' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      await pipeline(Readable.fromWeb(response.body), createWriteStream(`${target}.part`));
      await rename(`${target}.part`, target);
      console.log('ok');
      return target;
    } catch (error) {
      console.log(`failed (${error.message})`);
      if (attempt === tries) throw error;
      await new Promise((r) => setTimeout(r, 2000 * attempt));
    }
  }
}

export async function setup({ fresh = false } = {}) {
  mkdirSync(cache, { recursive: true });
  for (const [file, url] of Object.values(FILES)) await download(file, url);

  if (fresh) rmSync(site, { recursive: true, force: true });
  mkdirSync(site, { recursive: true });

  const install = await runPhp([
    'tests/bin/install-wp.php', '.cache', '.wp-test',
    FILES.wordpress[0], FILES.woocommerce[0], FILES.sqlite[0],
  ]);
  if (install !== 0) throw new Error(`install-wp.php exited with ${install}`);

  const first = await runPhp(['tests/bin/warm-up.php', '.wp-test'], { env: { WAPW_QUIET: '1' } });
  if (first !== 0) throw new Error(`warm-up.php exited with ${first}`);
  const warm = await runPhp(['tests/bin/warm-up.php', '.wp-test']);
  if (warm !== 0) throw new Error(`warm-up.php exited with ${warm}`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  setup({ fresh: process.argv.includes('--fresh') })
    .then(() => setTimeout(() => process.exit(0), 50))
    .catch((error) => {
      console.error(error.message);
      process.exit(1);
    });
}

function extractText(data) {
  if (!data) return null;
  return data;
}