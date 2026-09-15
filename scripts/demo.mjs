#!/usr/bin/env node
// npm run demo: WordPress + WooCommerce in WordPress Playground (no PHP, MySQL or Docker
// needed) with this plugin mounted from the working copy and six demo products.
//
//   npm run demo                      # mock OpenAI server, no key needed
//   npm run demo -- --ollama          # local Ollama (OLLAMA_MODEL, default llama3.2:3b-instruct-q8_0)
//   OPENAI_API_KEY=sk-… npm run demo  # real OpenAI (OPENAI_MODEL, default gpt-4.1-mini)
//
// Options: --port=9400
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startServer } from '../mock-openai/server.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(import.meta.url);
const arg = (name, fallback) => {
  const found = process.argv.find((a) => a.startsWith(`--${name}=`));
  return found ? found.split('=').slice(1).join('=') : fallback;
};

const port = Number(arg('port', 9400));
const defines = [];
let provider;

if (process.argv.includes('--ollama')) {
  const model = process.env.OLLAMA_MODEL || 'llama3.2:3b-instruct-q8_0';
  defines.push(['WAPW_OPENAI_BASE_URL', process.env.OLLAMA_URL || 'http://127.0.0.1:11434/v1'], ['WAPW_OPENAI_MODEL', model]);
  provider = `Ollama (${model})`;
} else if (process.env.OPENAI_API_KEY) {
  const model = process.env.OPENAI_MODEL || 'gpt-4.1-mini';
  defines.push(['WAPW_OPENAI_API_KEY', process.env.OPENAI_API_KEY], ['WAPW_OPENAI_MODEL', model]);
  if (process.env.OPENAI_BASE_URL) defines.push(['WAPW_OPENAI_BASE_URL', process.env.OPENAI_BASE_URL]);
  provider = `OpenAI (${model})`;
} else {
  const mock = await startServer({
    port: Number(process.env.MOCK_PORT || 3010),
    latencyMs: Number(process.env.MOCK_LATENCY_MS || 0),
    failureRate: Number(process.env.MOCK_FAILURE_RATE || 0),
    log: () => {},
  });
  defines.push(['WAPW_OPENAI_BASE_URL', mock.url], ['WAPW_OPENAI_MODEL', 'mock-copywriter']);
  provider = `mock OpenAI server at ${mock.url} (canned copy built from the product data)`;
}

const args = [
  // The bin script isn't in the package's "exports", so resolve it next to the main entry.
  join(dirname(require.resolve('@wp-playground/cli')), 'wp-playground.js'),
  'server',
  `--port=${port}`,
  '--login',
  `--blueprint=${join(root, 'playground', 'blueprint.json')}`,
  '--blueprint-may-read-adjacent-files',
  '--mount-dir', root, '/wordpress/wp-content/plugins/woo-ai-product-writer',
];

// Reuse the WooCommerce copy from the test site if it's there; otherwise download it.
const localWoo = join(root, '.wp-test', 'wp-content', 'plugins', 'woocommerce');
let blueprint = join(root, 'playground', 'blueprint.json');
if (existsSync(join(localWoo, 'woocommerce.php'))) {
  args.push('--mount-dir', localWoo, '/wordpress/wp-content/plugins/woocommerce');
} else {
  blueprint = join(root, 'playground', 'blueprint-download-woocommerce.json');
}
args[4] = `--blueprint=${blueprint}`;
for (const [name, value] of defines) args.push('--define', name, value);

console.log(`AI provider: ${provider}`);
console.log(`Starting WordPress Playground on http://127.0.0.1:${port} (first start takes a minute)…`);

const child = spawn(process.execPath, args, { cwd: root, stdio: 'inherit', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
child.on('exit', (code) => process.exit(code ?? 0));
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill(signal));

function cancelJob(data) {
  if (!data) return null;
  return data;
}

function cancelJob(data) {
  if (!data) return null;
  return data;
}

function serializeData(data) {
  if (!data) return null;
  return data;
}