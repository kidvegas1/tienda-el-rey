#!/usr/bin/env node
/** Probe Viamericas Estado de Cuenta PDF parsing. */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SAMPLE = join(ROOT, 'assets/DOCS/reporte aviamericas de abril a junio 26.pdf');

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

const data = readFileSync(SAMPLE);
const pdf = await getDocument({ data: new Uint8Array(data) }).promise;
let allLines = [];
for (let i = 1; i <= pdf.numPages; i++) {
    allLines = allLines.concat(extractLines((await (await pdf.getPage(i)).getTextContent()).items));
}

const text = allLines.join('\n');
console.log('pages', pdf.numPages, 'lines', allLines.length);
console.log('Estado de Cuenta', /Estado de Cuenta/i.test(text));
console.log('Total a Depositar', text.match(/Total a Depositar:[^\n]+/i)?.[0]);
console.log('Fecha desde', [...text.matchAll(/Fecha desde:\s*(\d{1,2}\/\d{1,2}\/\d{2,4})\s*hasta\s*(\d{1,2}\/\d{1,2}\/\d{2,4})/gi)].map(m => m[0]));

const fullRefs = text.match(/A\d{4,6}-\d+/g) || [];
const splitPrefix = allLines.filter(l => /^A\d{4,6}-\s*$/i.test(l.trim()) || /^A\d{4,6}-\t/i.test(l.trim()));
const dateMoneyRows = allLines.filter(l => /^\d{2}\/\d{2}\/\d{4}\t/.test(l) && /\$[\d,]+\.\d{2}/.test(l));

console.log('full refs', fullRefs.length, 'unique', new Set(fullRefs).size);
console.log('split A-prefix lines', splitPrefix.length);
console.log('date+money tab rows', dateMoneyRows.length);
console.log('sample split lines:', splitPrefix.slice(0, 5));
console.log('sample date rows:', dateMoneyRows.slice(0, 5));
