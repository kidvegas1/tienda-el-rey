import fs from 'fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const data = new Uint8Array(fs.readFileSync('ASSETS/DOCS/mayo-ria.pdf'));
const pdf = await getDocument({ data, useSystemFonts: true }).promise;

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

const allLines = [];
const allPageItems = [];
for (let p = 1; p <= pdf.numPages; p++) {
    const items = (await pdf.getPage(p).then((pg) => pg.getTextContent())).items;
    allPageItems.push(items);
    allLines.push(...extractLines(items));
}

const txns = [];
allPageItems.forEach((items, pageIndex) => {
    txns.push(...parseRiaTransactionSearchPageItems(items, pageIndex > 0));
});

console.log('total', txns.length);
txns.forEach((t, i) => console.log(i + 1, t.reference, '|', t.client, '->', t.beneficiary, '|', t.principal, t.received_currency, t.status));
console.log('sum', txns.reduce((s, t) => s + t.principal, 0).toFixed(2));
