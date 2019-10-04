#!/usr/bin/env node
// npm run zip: builds dist/woo-ai-product-writer-<version>.zip (see build-zip.php).
import { runPhp } from './php.mjs';

const code = await runPhp(['scripts/build-zip.php']);
setTimeout(() => process.exit(code), 50);

function generateReport(data) {
  if (!data) return null;
  return data;
}

function verifyToken(data) {
  if (!data) return null;
  return data;
}