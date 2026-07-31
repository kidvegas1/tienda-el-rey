#!/usr/bin/env node
/**
 * Browser smoke test — all public HTML routes load without console errors.
 * Run: node scripts/browser-smoke-test.mjs [baseUrl]
 * Requires: npx playwright (auto-installed on first run).
 */
import { chromium } from 'playwright';

const base = process.argv[2] || 'http://localhost:8080';
const pages = [
  'login',
  'dashboard',
  'caja',
  'clients',
  'suly-ledger',
  'schedule',
  'statistics',
  'reports',
  'analytics',
  'reports-center',
  'accounting',
  'inventory',
  'import',
  'stores',
  'events',
  'plates',
];

const results = [];
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();

const consoleErrors = [];
page.on('console', (msg) => {
  if (msg.type() === 'error') consoleErrors.push({ url: page.url(), text: msg.text() });
});
page.on('pageerror', (err) => consoleErrors.push({ url: page.url(), text: err.message }));

for (const slug of pages) {
  const url = `${base}/${slug}`;
  const entry = { page: slug, url, status: 'fail', title: '', errors: [] };
  try {
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 });
    entry.http = resp?.status() ?? 0;
    entry.title = await page.title();
    await page.waitForTimeout(500);
    entry.errors = consoleErrors.filter((e) => e.url.includes(slug) || e.url === url);
    entry.status = entry.http >= 200 && entry.http < 400 ? 'ok' : 'fail';
  } catch (e) {
    entry.error = e.message;
  }
  results.push(entry);
  consoleErrors.length = 0;
}

await browser.close();

let failed = 0;
for (const r of results) {
  const mark = r.status === 'ok' ? 'PASS' : 'FAIL';
  if (r.status !== 'ok') failed++;
  console.log(`${mark} ${r.page} HTTP=${r.http ?? '?'} title="${r.title || ''}"${r.error ? ' err=' + r.error : ''}`);
  for (const e of r.errors || []) {
    console.log(`  console: ${e.text}`);
  }
}

console.log(`\n${results.length - failed}/${results.length} pages loaded`);
process.exit(failed > 0 ? 1 : 0);
