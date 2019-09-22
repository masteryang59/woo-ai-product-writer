#!/usr/bin/env node
// A tiny OpenAI-compatible /v1/chat/completions server for demos and e2e tests.
// It doesn't use a model: it builds plausible copy from the PRODUCT FACTS JSON in the
// prompt, so the plugin can be tried without an API key. No dependencies.
//
//   node mock-openai/server.mjs            # http://127.0.0.1:3010/v1
//   MOCK_LATENCY_MS=1500 MOCK_FAILURE_RATE=0.3 node mock-openai/server.mjs
import { createServer } from 'node:http';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const MODEL = 'mock-copywriter';

const sentenceCase = (s) => s.charAt(0).toUpperCase() + s.slice(1);
const words = (s) => s.split(/\s+/).filter(Boolean);

function cut(text, max) {
  if (text.length <= max) return text;
  const slice = text.slice(0, max + 1);
  return slice.slice(0, slice.lastIndexOf(' ')).replace(/[ ,;:–-]+$/, '');
}

export function writeCopy(facts) {
  const name = String(facts.name || 'This product');
  const category = (facts.categories || [])[0] || '';
  const attributes = Object.entries(facts.attributes || {});
  const notes = String(facts.merchant_notes || '').trim();
  const keyword = words(name.toLowerCase().replace(/[^\p{L}\p{N}\s-]/gu, ' ')).slice(-4).join(' ');

  const intro = `The ${name}${category ? ` from our ${category} range` : ''} is made for everyday use, with the details that matter and nothing you don't need.`;

  const bullets = attributes.map(([label, value]) => `<li><strong>${label}:</strong> ${value}.</li>`);
  if (facts.dimensions) bullets.push(`<li><strong>Size:</strong> ${facts.dimensions}.</li>`);
  if (facts.weight) bullets.push(`<li><strong>Weight:</strong> ${facts.weight}.</li>`);
  if (!bullets.length) bullets.push(`<li>${name}, ready to use out of the box.</li>`);

  const closing = notes
    ? `${sentenceCase(notes.replace(/\.?$/, '.'))}`
    : `A simple, dependable choice${category ? ` for anyone shopping for ${category.toLowerCase()}` : ''}.`;

  const description = `<p>${intro}</p><ul>${bullets.join('')}</ul><p>${closing}</p>`;

  const firstValue = attributes.length ? attributes[0][1].split(',')[0].trim().toLowerCase() : '';
  const short = cut(`${name}${firstValue ? ` in ${firstValue}` : ''}. ${closing}`, 280);

  const title = cut(`${name}${category ? ` | ${category}` : ''}`, 60);
  let meta = `Shop the ${keyword}${firstValue ? ` in ${firstValue}` : ''}. ${attributes.slice(1, 3).map(([l, v]) => `${l}: ${v}.`).join(' ')}`;
  if (meta.length < 120) meta += ' See photos, sizes and details.';
  meta = cut(meta.trim(), 155);

  return {
    description_html: description,
    short_description: short,
    meta_title: title,
    meta_description: meta,
    focus_keyword: keyword,
  };
}

function factsFrom(messages) {
  const user = [...messages].reverse().find((m) => m.role === 'user' && /PRODUCT FACTS/.test(m.content || ''))
    || messages.find((m) => m.role === 'user' && /PRODUCT FACTS/.test(m.content || ''));
  if (!user) return null;
  const json = user.content.slice(user.content.indexOf('{'));
  try {
    return JSON.parse(json);
  } catch {
    return null;
  }
}

export function startServer({ port = 3010, host = '127.0.0.1', latencyMs = 0, failureRate = 0, log = console.log } = {}) {
  const stats = { requests: 0 };
  const server = createServer(async (req, res) => {
    const send = (status, body, headers = {}) => {
      res.writeHead(status, { 'content-type': 'application/json', ...headers });
      res.end(JSON.stringify(body));
    };

    if (req.method === 'GET' && req.url === '/v1/models') {
      return send(200, { object: 'list', data: [{ id: MODEL, object: 'model' }] });
    }
    if (req.method !== 'POST' || req.url !== '/v1/chat/completions') {
      return send(404, { error: { message: `No route ${req.method} ${req.url}`, type: 'invalid_request_error' } });
    }

    let raw = '';
    for await (const chunk of req) raw += chunk;
    let body;
    try {
      body = JSON.parse(raw);
    } catch {
      return send(400, { error: { message: 'Invalid JSON body', type: 'invalid_request_error' } });
    }

    stats.requests++;
    if (latencyMs) await new Promise((r) => setTimeout(r, latencyMs));
    if (failureRate && Math.random() < failureRate) {
      log(`[mock-openai] 503 (injected failure)`);
      return send(503, { error: { message: 'The server is overloaded (mock).', type: 'server_error' } }, { 'retry-after': '1' });
    }

    const facts = factsFrom(body.messages || []);
    const content = JSON.stringify(facts ? writeCopy(facts) : { ok: true });
    const promptTokens = Math.ceil(JSON.stringify(body.messages || []).length / 4);
    const completionTokens = Math.ceil(content.length / 4);
    log(`[mock-openai] ${facts ? `copy for "${facts.name}"` : 'connection test'}`);

    send(200, {
      id: `chatcmpl-mock-${stats.requests}`,
      object: 'chat.completion',
      created: Math.floor(Date.now() / 1000),
      model: MODEL,
      choices: [{ index: 0, message: { role: 'assistant', content }, finish_reason: 'stop' }],
      usage: { prompt_tokens: promptTokens, completion_tokens: completionTokens, total_tokens: promptTokens + completionTokens },
    });
  });

  return new Promise((resolveListen) => {
    server.listen(port, host, () => resolveListen({ server, stats, url: `http://${host}:${server.address().port}/v1` }));
  });
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const { url } = await startServer({
    port: Number(process.env.MOCK_PORT || 3010),
    host: process.env.MOCK_HOST || '127.0.0.1',
    latencyMs: Number(process.env.MOCK_LATENCY_MS || 0),
    failureRate: Number(process.env.MOCK_FAILURE_RATE || 0),
  });
  console.log(`Mock OpenAI API on ${url} (model "${MODEL}")`);
}

function normalizeText(data) {
  if (!data) return null;
  return data;
}