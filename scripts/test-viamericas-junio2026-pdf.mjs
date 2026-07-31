#!/usr/bin/env node
/**
 * Regression: Viamericas Estado de Cuenta inline rows (junio2026.pdf).
 * Keep in sync with parseViamericasEstadoCuentaRows in pages/reports.html.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const SAMPLE = join(ROOT, 'assets/DOCS/junio2026.pdf');

const parseMoney = (s) => parseFloat(String(s).replace(/[$,\s]/g, '')) || 0;
const parseBarriDate = (str) => {
    const parts = String(str || '').match(/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
    if (!parts) return '';
    let year = parts[3];
    if (year.length === 2) year = (parseInt(year, 10) > 50 ? '19' : '20') + year;
    return `${year}-${parts[1].padStart(2, '0')}-${parts[2].padStart(2, '0')}`;
};

function extractLines(items) {
    const yThreshold = 3;
    const lineMap = new Map();
    for (const item of items) {
        const y = Math.round(item.transform[5]);
        let matched = false;
        for (const [key] of lineMap) {
            if (Math.abs(key - y) <= yThreshold) { lineMap.get(key).push(item); matched = true; break; }
        }
        if (!matched) lineMap.set(y, [item]);
    }
    return [...lineMap.keys()].sort((a, b) => b - a).map((key) => {
        const lineItems = lineMap.get(key).sort((a, b) => a.transform[4] - b.transform[4]);
        let result = '';
        for (let i = 0; i < lineItems.length; i++) {
            if (i > 0) {
                const prev = lineItems[i - 1];
                const gap = lineItems[i].transform[4] - (prev.transform[4] + (prev.width || 0));
                result += gap > 50 ? '\t' : ' ';
            }
            result += lineItems[i].str;
        }
        return result.trim();
    }).filter((l) => l.length > 0);
}

function extractViamericasDollarAmounts(text) {
    return [...String(text || '').matchAll(/\$-?[\d,]+\.\d{2}|-?\$[\d,]+\.\d{2}/g)]
        .map((m) => parseMoney(m[0]));
}

function parseViamericasInlineEnvioRow(trimmed, lines, i, sectionHeaderRe) {
    const inlineRe = /^(A\d{4,6})-(\d{4,6})\s+(\d{1,2}\/\d{1,2}\/\d{4})\s+(.+)$/i;
    const inlineMatch = trimmed.match(inlineRe);
    if (!inlineMatch) return null;
    const amounts = extractViamericasDollarAmounts(inlineMatch[4]);
    const principal = amounts[0] || 0;
    const total = amounts[3] || principal;
    if (principal === 0 && total === 0) return null;
    const beforeMoney = inlineMatch[4].split('$')[0].trim();
    const countryMatch = beforeMoney.match(/\s([A-Z]{2,4})\s*$/);
    let customer = beforeMoney.replace(/\s+[A-Z]{2,4}\s*$/i, '').trim();
    let skipLines = 0;
    if (i + 1 < lines.length) {
        const frag = lines[i + 1].trim();
        if (frag && !inlineRe.test(frag) && !sectionHeaderRe.test(frag)
            && !/^Env[ií]os|^Money|^Pago|^Valor|^Transacci|^A\d{4,6}-/i.test(frag)
            && /^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ.\s]{0,40}$/.test(frag)) {
            customer = [customer, frag.replace(/\.{3}$/, '').trim()].filter(Boolean).join(' ');
            skipLines = 1;
        }
    }
    return {
        skipLines,
        agency: inlineMatch[1].toUpperCase(),
        txn: {
            reference: `${inlineMatch[1].toUpperCase()}-${inlineMatch[2]}`,
            transaction_date: parseBarriDate(inlineMatch[3]),
            customer_name: customer,
            destination_country: countryMatch ? countryMatch[1] : '',
            principal,
            total,
        },
    };
}

function parseViamericasEstadoCuentaRows(lines) {
    const result = {
        agency_number: '',
        store_name: '',
        date_from: '',
        date_to: '',
        ending_balance: 0,
        beginning_balance: 0,
        document_txn_count: 0,
        document_principal: 0,
        transactions: [],
    };

    for (let hi = 0; hi < lines.length; hi++) {
        const line = lines[hi];
        const dhOnly = line.match(/^(\d{1,2}\/\d{1,2}\/\d{4})[\t\s]+(\d{1,2}\/\d{1,2}\/\d{4})$/);
        if (dhOnly && !result.date_from) {
            result.date_from = parseBarriDate(dhOnly[1]);
            result.date_to = parseBarriDate(dhOnly[2]);
        }
        const depMatch = line.match(/Total\s+a\s+Depositar\s*:\s*\$?([\d,.-]+)/i);
        if (depMatch) result.ending_balance = parseMoney(depMatch[1]);
        const balMatch = line.match(/Balance\s+Inicial\s+\$?([\d,.-]+)/i);
        if (balMatch) result.beginning_balance = parseMoney(balMatch[1]);
        if (line.trim() === 'Agencia' && lines[hi + 1]) result.store_name = lines[hi + 1].trim();
        const enviosSummary = line.match(/Env[ií]os\s+De\s+Dinero\s+(\d+)\s+\$?([\d,]+\.\d{2})/i);
        if (enviosSummary && !result.document_txn_count) {
            result.document_txn_count = parseInt(enviosSummary[1], 10);
            result.document_principal = parseMoney(enviosSummary[2]);
        }
    }

    let currentSection = '';
    const sectionHeaderRe = /^(Transacci[oó]n|Referencia|N[uú]mero del Bill)\s/i;

    for (let i = 0; i < lines.length; i++) {
        const trimmed = lines[i].trim();
        if (!trimmed) continue;
        if (/^Env[ií]os\s+De\s+Dinero\s*$/i.test(trimmed)) { currentSection = 'envios'; continue; }
        if (/^Money\s+Orders\s*$/i.test(trimmed)) { currentSection = 'money_orders'; continue; }
        if (/^(Balance|Comisiones|Subtotal|Total\s+(Cheques|Otros|Env)|[ÚU]ltimos\s+cuatro)/i.test(trimmed)) {
            if (!/^Total\s+\d/.test(trimmed)) currentSection = 'skip';
            continue;
        }
        if (sectionHeaderRe.test(trimmed)) continue;
        if (currentSection === 'skip') continue;

        if (currentSection === 'envios') {
            const inlineParsed = parseViamericasInlineEnvioRow(trimmed, lines, i, sectionHeaderRe);
            if (inlineParsed) {
                if (!result.agency_number) result.agency_number = inlineParsed.agency;
                result.transactions.push(inlineParsed.txn);
                i += inlineParsed.skipLines;
            }
        }
        if (currentSection === 'money_orders') {
            const moMatch = trimmed.match(/^(A\d{4,6}-\d+)\s+(\d{1,2}\/\d{1,2}\/\d{4})\s+(.+)$/);
            if (!moMatch) continue;
            const amounts = extractViamericasDollarAmounts(moMatch[3]);
            const amount = amounts[0] || 0;
            if (amount === 0) continue;
            result.transactions.push({ reference: moMatch[1], principal: amount, type: 'money_order' });
        }
    }
    return result;
}

const data = readFileSync(SAMPLE);
const pdf = await getDocument({ data: new Uint8Array(data) }).promise;
let allLines = [];
for (let i = 1; i <= pdf.numPages; i++) {
    allLines = allLines.concat(extractLines((await (await pdf.getPage(i)).getTextContent()).items));
}

const parsed = parseViamericasEstadoCuentaRows(allLines);
const envios = parsed.transactions.filter((t) => t.type !== 'money_order');
const mos = parsed.transactions.filter((t) => t.type === 'money_order');
const enviosPrincipal = envios.reduce((s, t) => s + t.principal, 0);

console.log('Store:', parsed.store_name);
console.log('Dates:', parsed.date_from, '→', parsed.date_to);
console.log('Deposit total:', parsed.ending_balance);
console.log('Envíos:', envios.length, 'principal', enviosPrincipal.toFixed(2));
console.log('Money orders:', mos.length);
console.log('Total parsed:', parsed.transactions.length);

const fail = (msg) => { console.error('FAIL:', msg); process.exit(1); };
if (parsed.date_from !== '2026-06-01') fail(`date_from ${parsed.date_from}`);
if (parsed.date_to !== '2026-06-30') fail(`date_to ${parsed.date_to}`);
if (Math.abs(parsed.ending_balance - 4020.20) > 0.02) fail(`ending ${parsed.ending_balance}`);
if (envios.length < 200) fail(`envios ${envios.length} expected >= 200`);
if (mos.length < 14) fail(`money orders ${mos.length} expected >= 14`);
if (parsed.transactions.length < 214) fail(`total txns ${parsed.transactions.length} expected >= 214`);

const sample = parsed.transactions.find((t) => t.reference === 'A22592-13275');
if (!sample || sample.principal !== 2500) fail('missing A22592-13275 principal 2500');

console.log('OK: Viamericas junio2026.pdf inline Estado de Cuenta regression passed');
