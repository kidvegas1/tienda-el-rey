#!/usr/bin/env node
/**
 * Regression: Viamericas Estado de Cuenta PDF (Apr–Jun 2026 sample).
 * Keep in sync with parseViamericasEstadoCuentaRows in pages/reports.html.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const SAMPLE = join(ROOT, 'assets/DOCS/reporte aviamericas de abril a junio 26.pdf');

const parseMoney = (s) => {
    if (!s) return 0;
    return parseFloat(String(s).replace(/[$,\s]/g, '')) || 0;
};

const parseBarriDate = (str) => {
    const parts = String(str || '').match(/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
    if (!parts) return '';
    const month = parts[1].padStart(2, '0');
    const day = parts[2].padStart(2, '0');
    let year = parts[3];
    if (year.length === 2) year = (parseInt(year, 10) > 50 ? '19' : '20') + year;
    return `${year}-${month}-${day}`;
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

function parseViamericasEstadoCuentaRows(lines) {
    const result = {
        agency_number: '',
        agency_name: '',
        agency_address: '',
        operator_number: '',
        date_from: '',
        date_to: '',
        currency: 'USD',
        beginning_balance: 0,
        ending_balance: 0,
        company: 'Viamericas',
        store_name: '',
        report_format: 'viamericas_estado_cuenta',
        transactions: [],
        totals: { qty: 0, principal: 0, fee: 0, tax: 0, total: 0, agcomm: 0, var_fee: 0, var_fx: 0 },
    };

    for (const line of lines) {
        const rangeMatch = line.match(/Fecha\s+desde:\s*(\d{1,2}\/\d{1,2}\/\d{2,4})\s*hasta\s*(\d{1,2}\/\d{1,2}\/\d{2,4})/i);
        if (rangeMatch) {
            result.date_from = parseBarriDate(rangeMatch[1]);
            result.date_to = parseBarriDate(rangeMatch[2]);
        }
        const depMatch = line.match(/Total\s+a\s+Depositar:\s*\$?([\d,.-]+)/i);
        if (depMatch) result.ending_balance = parseMoney(depMatch[1]);
        const balMatch = line.match(/Balance\s+Inicial\s+\$?([\d,.-]+)/i);
        if (balMatch) result.beginning_balance = parseMoney(balMatch[1]);
        const storeMatch = line.match(/VIAMERICAS\s+CORPORATION\s+(.+)/i);
        if (storeMatch) result.store_name = storeMatch[1].trim();
    }

    let currentSection = '';
    const sectionHeaderRe = /^(Transacci[oó]n|Referencia|N[uú]mero del Bill)\s/i;

    for (let i = 0; i < lines.length; i++) {
        const trimmed = lines[i].trim();
        if (!trimmed) continue;

        if (/^Env[ií]os\s+De\s+Dinero\s*-\s*Cancelados/i.test(trimmed)) {
            currentSection = 'cancelados'; continue;
        }
        if (/^Env[ií]os\s+De\s+Dinero\s*-\s*Anulados/i.test(trimmed)) {
            currentSection = 'anulados'; continue;
        }
        if (/^Env[ií]os\s+De\s+Dinero\s*$/i.test(trimmed)) {
            currentSection = 'envios'; continue;
        }
        if (/^Pago\s+de\s+Env[ií]os\s*$/i.test(trimmed)) {
            currentSection = 'pago_envios'; continue;
        }
        if (/^Pago\s+de\s+Biles\s*$/i.test(trimmed)) {
            currentSection = 'biles'; continue;
        }
        if (/^Money\s+Orders\s*$/i.test(trimmed)) {
            currentSection = 'money_orders'; continue;
        }
        if (/^Depositar$/i.test(trimmed) || /^Valor a$/i.test(trimmed) || /^Pagado$/i.test(trimmed) || /^Valor$/i.test(trimmed)) {
            continue;
        }
        if (/^(Balance|Comisiones|Cheques|Dep[oó]sitos|D[eé]bitos|Cr[eé]ditos|Otros\s+Servicios|Subtotal|Total\s+(Cheques|Otros|Env|Cr|D[eé]b|Dep)|[ÚU]ltimos\s+cuatro|Internacionales$|Cancelados$|Anulados$)/i.test(trimmed)) {
            if (!/^Total\s+\d/.test(trimmed)) currentSection = 'skip';
            continue;
        }
        if (sectionHeaderRe.test(trimmed) || /^Otros\s+Valor/i.test(trimmed) || /^Cargos Depositar$/i.test(trimmed)) {
            continue;
        }
        if (currentSection === 'skip' || currentSection === 'anulados') continue;

        // Envíos / Cancelados — 3-line block: A10556-\tNAME → date row → txnNum name
                if (currentSection === 'envios' || currentSection === 'cancelados') {
                    const prefixMatch = trimmed.match(/^(A\d{4,6})-[\t\s]+(.+)$/);
            if (!prefixMatch) continue;
            const agency = prefixMatch[1].toUpperCase();
            const namePart1 = prefixMatch[2].trim();
            if (i + 2 >= lines.length) continue;
            const dateLine = lines[i + 1].trim();
            const dateMatch = dateLine.match(/^(\d{1,2}\/\d{1,2}\/\d{4})\s+([A-Z]{2,4})\s+(.+)$/);
            if (!dateMatch) continue;
            const txnLine = lines[i + 2].trim();
            const txnMatch = txnLine.match(/^(-?\d{4,6})\s*(.*)$/);
            if (!txnMatch) continue;

            const txnNum = Math.abs(parseInt(txnMatch[1], 10));
            const reference = `${agency}-${txnNum}`;
            const customer = [namePart1, (txnMatch[2] || '').replace(/\.{3}$/, '').trim()].filter(Boolean).join(' ').trim();
            const amounts = extractViamericasDollarAmounts(dateMatch[3]);
            const principal = amounts[0] || 0;
            const fee = amounts[1] || 0;
            const tax = amounts[2] || 0;
            const total = amounts[3] || principal;
            const commission = amounts[4] || 0;
            const viaTasa = amounts[5] || 0;
            const otros = amounts[6] || 0;
            if (principal === 0 && total === 0) { i += 2; continue; }

            if (!result.agency_number) result.agency_number = agency;
            result.transactions.push({
                reference,
                transaction_date: parseBarriDate(dateMatch[1]),
                customer_name: customer,
                destination_country: dateMatch[2],
                type: 'money_transfer',
                principal, fee, tax, total,
                agcomm: commission,
                var_fee: viaTasa,
                var_fx: otros,
                transaction_status: currentSection === 'cancelados' ? 'cancelled' : '',
                time: '00:00', operator: '', qty: 1, balance: 0, beneficiary: '',
            });
            i += 2;
            continue;
        }

        if (currentSection === 'pago_envios') {
            const peMatch = trimmed.match(/^(\d{4,6})\s+(\d{1,2}\/\d{1,2}\/\d{4})\s+(.+)$/);
            if (!peMatch) continue;
            const amounts = extractViamericasDollarAmounts(peMatch[3]);
            const beneficiary = peMatch[3].split('$')[0].trim();
            const amount = amounts[0] || 0;
            const commission = amounts[1] || 0;
            if (amount === 0) continue;
            result.transactions.push({
                reference: peMatch[1],
                transaction_date: parseBarriDate(peMatch[2]),
                customer_name: beneficiary,
                type: 'pago_envios',
                principal: amount, fee: 0, tax: 0, total: amount,
                agcomm: commission,
                time: '00:00', operator: '', qty: 1, balance: 0, beneficiary,
            });
            continue;
        }

        if (currentSection === 'money_orders') {
            const moMatch = trimmed.match(/^(A\d{4,6}-\d+)\s+(\d{1,2}\/\d{1,2}\/\d{4})\s+(.+)$/);
            if (!moMatch) continue;
            const amounts = extractViamericasDollarAmounts(moMatch[3]);
            const amount = amounts[0] || 0;
            const fee = amounts[1] || 0;
            if (amount === 0) continue;
            if (!result.agency_number) {
                const ag = moMatch[1].match(/^(A\d{4,6})-/);
                if (ag) result.agency_number = ag[1];
            }
            result.transactions.push({
                reference: moMatch[1],
                transaction_date: parseBarriDate(moMatch[2]),
                customer_name: 'Money Order',
                type: 'money_order',
                principal: amount, fee, tax: 0, total: amount + fee,
                agcomm: 0,
                time: '00:00', operator: '', qty: 1, balance: 0,
            });
            continue;
        }

        if (currentSection === 'biles') {
            const billMatch = trimmed.match(/^(A\d{4,6}-\d+)\s+(\d{1,2}\/\d{1,2}\/\d{4})[\t\s]+(.+)$/);
            if (!billMatch) continue;
            const amounts = extractViamericasDollarAmounts(billMatch[3]);
            const nameParts = billMatch[3].split('$')[0].trim().split(/\t+/);
            const customer = (nameParts[0] || '').trim();
            const company = (nameParts[1] || '').trim();
            const amount = amounts[0] || 0;
            const commission = amounts[1] || 0;
            const fee = amounts[2] || 0;
            if (amount === 0) continue;
            if (!result.agency_number) {
                const ag = billMatch[1].match(/^(A\d{4,6})-/);
                if (ag) result.agency_number = ag[1];
            }
            result.transactions.push({
                reference: billMatch[1],
                transaction_date: parseBarriDate(billMatch[2]),
                customer_name: customer,
                beneficiary: company,
                type: 'bill_payment',
                principal: amount, fee, tax: 0, total: amounts[3] || amount + fee,
                agcomm: commission,
                time: '00:00', operator: '', qty: 1, balance: 0,
            });
        }
    }

    if (result.transactions.length > 0) {
        result.totals.qty = result.transactions.length;
        result.totals.principal = result.transactions.reduce((s, t) => s + t.principal, 0);
        result.totals.fee = result.transactions.reduce((s, t) => s + t.fee, 0);
        result.totals.tax = result.transactions.reduce((s, t) => s + t.tax, 0);
        result.totals.total = result.transactions.reduce((s, t) => s + t.total, 0);
        result.totals.agcomm = result.transactions.reduce((s, t) => s + t.agcomm, 0);
        if (!result.date_from) {
            const dates = result.transactions.map((t) => t.transaction_date).filter(Boolean).sort();
            result.date_from = dates[0] || '';
            result.date_to = dates[dates.length - 1] || result.date_from;
        }
    }
    if (!result.agency_name) {
        result.agency_name = result.agency_number ? `Viamericas ${result.agency_number}` : 'Viamericas Agency';
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
console.log('Agency:', parsed.agency_number, 'Store:', parsed.store_name);
console.log('Dates:', parsed.date_from, '→', parsed.date_to);
console.log('Balances: begin', parsed.beginning_balance, 'deposit', parsed.ending_balance);
console.log('Transactions:', parsed.transactions.length);
console.log('By type:', parsed.transactions.reduce((m, t) => { m[t.type + (t.transaction_status ? ':' + t.transaction_status : '')] = (m[t.type + (t.transaction_status ? ':' + t.transaction_status : '')] || 0) + 1; return m; }, {}));

const fail = (msg) => { console.error('FAIL:', msg); process.exit(1); };
if (parsed.agency_number !== 'A10556') fail(`agency ${parsed.agency_number}`);
if (parsed.date_from !== '2026-04-01') fail(`date_from ${parsed.date_from}`);
if (parsed.date_to !== '2026-04-30') fail(`date_to ${parsed.date_to}`);
if (Math.abs(parsed.ending_balance - 1579.41) > 0.02) fail(`ending ${parsed.ending_balance}`);
if (Math.abs(parsed.beginning_balance - 11323.10) > 0.02) fail(`beginning ${parsed.beginning_balance}`);
if (parsed.transactions.length < 900) fail(`txns ${parsed.transactions.length} expected >= 900`);

const sample = parsed.transactions.find((t) => t.reference === 'A10556-76161');
if (!sample || sample.principal !== 900) fail('missing sample A10556-76161 principal 900');

const cancelled = parsed.transactions.filter((t) => t.transaction_status === 'cancelled');
if (cancelled.length < 10) fail(`cancelled ${cancelled.length} expected >= 10`);

// Browser PDF.js may join columns with spaces instead of tabs when gap threshold differs
function extractLinesGap(items, gapTh) {
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
                result += gap > gapTh ? '\t' : ' ';
            }
            result += lineItems[i].str;
        }
        return result.trim();
    }).filter((l) => l.length > 0);
}

const pdf2 = await getDocument({ data: new Uint8Array(data) }).promise;
let spaceLines = [];
for (let i = 1; i <= pdf2.numPages; i++) {
    spaceLines = spaceLines.concat(extractLinesGap((await (await pdf2.getPage(i)).getTextContent()).items, 80));
}
const spaceParsed = parseViamericasEstadoCuentaRows(spaceLines);
if (spaceParsed.transactions.length < 900) fail(`space-gap txns ${spaceParsed.transactions.length} expected >= 900`);

console.log('OK: Viamericas Estado de Cuenta PDF regression passed');
