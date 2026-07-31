import fs from 'fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const BASE = process.env.SMOKE_BASE_URL || 'http://localhost:8080';
const SMOKE_EMAIL = process.env.SMOKE_EMAIL;
const SMOKE_PASSWORD = process.env.SMOKE_PASSWORD;

if (!SMOKE_EMAIL || !SMOKE_PASSWORD) {
    throw new Error('Set SMOKE_EMAIL and SMOKE_PASSWORD to an existing test account.');
}

function extractLines(items) {
    const yThreshold = 3;
    const lineMap = new Map();
    for (const item of items) {
        if (!item.str?.trim()) continue;
        const y = Math.round(item.transform[5]);
        let key = null;
        for (const existingKey of lineMap.keys()) {
            if (Math.abs(existingKey - y) <= yThreshold) { key = existingKey; break; }
        }
        if (key === null) { key = y; lineMap.set(key, []); }
        lineMap.get(key).push(item);
    }
    return [...lineMap.entries()].sort((a, b) => b[0] - a[0]).map(([_, rowItems]) => {
        const sorted = rowItems.sort((a, b) => a.transform[4] - b.transform[4]);
        let result = '';
        for (let i = 0; i < sorted.length; i++) {
            const item = sorted[i];
            if (i > 0) {
                const prev = sorted[i - 1];
                const gap = item.transform[4] - (prev.transform[4] + (prev.width || 0));
                result += gap > 50 ? '\t' : ' ';
            }
            result += item.str;
        }
        return result.trim();
    }).filter(Boolean);
}

function parseBarriDate(str) {
    const parts = str.match(/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
    if (!parts) return str;
    const month = parts[1].padStart(2, '0');
    const day = parts[2].padStart(2, '0');
    let year = parts[3];
    if (year.length === 2) year = (parseInt(year) > 50 ? '19' : '20') + year;
    return `${year}-${month}-${day}`;
}

function buildRiaTransactionSearchResult(lines) {
    const allText = lines.join(' ');
    const result = {
        agency_number: '',
        agency_name: 'Ria Agency',
        agency_address: '',
        operator_number: '',
        date_from: '',
        date_to: '',
        currency: 'USD',
        beginning_balance: 0,
        ending_balance: 0,
        company: 'Ria',
        transactions: [],
        totals: { qty: 0, principal: 0, fee: 0, tax: 0, total: 0, agcomm: 0, var_fee: 0, var_fx: 0 },
    };
    const dateRange = allText.match(/(\d{1,2}\/\d{1,2}\/\d{2,4}).*?(\d{1,2}\/\d{1,2}\/\d{2,4})/);
    if (dateRange) {
        result.date_from = parseBarriDate(dateRange[1]);
        result.date_to = parseBarriDate(dateRange[2]);
    }
    for (const line of lines) {
        const agentMatch = line.match(/Sulys[^\t.]+/i);
        if (agentMatch && !result.agency_name) result.agency_name = agentMatch[0].trim();
    }
    return result;
}

function parseRiaTransactionSearchPageItems(items, continuationPage = false) {
    const yThreshold = 3;
    const lineMap = new Map();
    for (const item of items) {
        if (!item.str?.trim()) continue;
        const y = Math.round(item.transform[5]);
        let key = null;
        for (const existingKey of lineMap.keys()) {
            if (Math.abs(existingKey - y) <= yThreshold) { key = existingKey; break; }
        }
        if (key === null) { key = y; lineMap.set(key, []); }
        lineMap.get(key).push(item);
    }
    const colForX = (x) => {
        if (x < 130) return 'order';
        if (x < 170) return 'seq';
        if (x < 210) return 'client';
        if (x < 275) return 'beneficiary';
        if (x < 320) return 'amount';
        if (x < 400) return 'foreign';
        return 'status';
    };
    const rows = [...lineMap.entries()].sort((a, b) => b[0] - a[0]).map(([_, rowItems]) => {
        const cells = { order: [], seq: [], client: [], beneficiary: [], amount: [], foreign: [], status: [] };
        rowItems.sort((a, b) => a.transform[4] - b.transform[4]).forEach((item) => {
            cells[colForX(Math.round(item.transform[4]))].push(item.str.trim());
        });
        const orderMatch = cells.order.join(' ').trim().match(/(US\d{9,10})/);
        return { cells, order: orderMatch ? orderMatch[1] : '', amount: cells.amount.join(' ').trim() };
    });

    let started = continuationPage;
    const txns = [];
    let current = null;
    for (const row of rows) {
        const lineText = Object.values(row.cells).flat().join(' ');
        if (!started) {
            if (/No\.\s*Orden/i.test(lineText) && /Monto\s+Local/i.test(lineText)) started = true;
            continue;
        }
        const isTxnStart = /^US\d{9,10}$/.test(row.order) && /[\d,]+\.\d{2}\s*USD/i.test(row.amount);
        if (isTxnStart) {
            if (current) txns.push(current);
            const foreignMatch = row.cells.foreign.join(' ').match(/([\d,]+\.\d{2})\s+([A-Z]{3})/);
            current = {
                reference: row.order,
                client: row.cells.client.join(' ').trim(),
                beneficiary: row.cells.beneficiary.join(' ').trim(),
                principal: parseFloat((row.amount.match(/([\d,]+\.\d{2})\s*USD/i) || [])[1]?.replace(/,/g, '') || '0'),
                amount_received: foreignMatch ? parseFloat(foreignMatch[1].replace(/,/g, '')) : 0,
                received_currency: foreignMatch ? foreignMatch[2] : '',
                status: row.cells.status.join(' ').replace(/Acción/gi, '').trim(),
            };
        } else if (current && (row.cells.client.length || row.cells.beneficiary.length) && !row.order) {
            if (row.cells.client.length) current.client += ' ' + row.cells.client.join(' ');
            if (row.cells.beneficiary.length) current.beneficiary += ' ' + row.cells.beneficiary.join(' ');
        }
    }
    if (current) txns.push(current);
    return txns;
}

const data = new Uint8Array(fs.readFileSync('ASSETS/DOCS/mayo-ria.pdf'));
const pdf = await getDocument({ data, useSystemFonts: true }).promise;
const allLines = [];
const allPageItems = [];
for (let p = 1; p <= pdf.numPages; p++) {
    const items = (await pdf.getPage(p).then((pg) => pg.getTextContent())).items;
    allPageItems.push(items);
    allLines.push(...extractLines(items));
}

const result = buildRiaTransactionSearchResult(allLines);
const rawTxns = [];
allPageItems.forEach((items, pageIndex) => {
    rawTxns.push(...parseRiaTransactionSearchPageItems(items, pageIndex > 0));
});
result.transactions = rawTxns.map((txn) => ({
    time: '00:00',
    type: 'giros',
    reference: txn.reference,
    customer_name: txn.client,
    beneficiary: txn.beneficiary,
    operator: '',
    qty: 1,
    principal: txn.principal,
    fee: 0,
    tax: 0,
    total: txn.principal,
    balance: 0,
    agcomm: 0,
    var_fee: 0,
    var_fx: 0,
    transaction_date: result.date_from,
    amount_received: txn.amount_received,
    received_currency: txn.received_currency,
    transaction_status: txn.status,
}));
result.totals.qty = result.transactions.length;
result.totals.principal = result.transactions.reduce((sum, txn) => sum + txn.principal, 0);
result.totals.total = result.totals.principal;

const loginRes = await fetch(`${BASE}/api/auth`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'login', email: SMOKE_EMAIL, password: SMOKE_PASSWORD }),
});
const login = await loginRes.json();
if (!login.success) throw new Error('Login failed: ' + JSON.stringify(login));

const cookie = loginRes.headers.get('set-cookie')?.split(';')[0] || '';
const fd = new FormData();
fd.append('action', 'import');
fd.append('store_id', '3');
fd.append('data', JSON.stringify(result));
fd.append('pdf_file', new Blob([fs.readFileSync('ASSETS/DOCS/mayo-ria.pdf')], { type: 'application/pdf' }), 'mayo-ria.pdf');

const importRes = await fetch(`${BASE}/api/barri-reports`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': login.csrf, Cookie: cookie },
    body: fd,
});
const importBody = await importRes.json();
console.log('import status', importRes.status);
console.log(JSON.stringify(importBody, null, 2));
