// Verifies parseRiaTransactionReport (extracted from pages/reports.html) against
// a real RIA "Reporte de Transacciones" PDF.
// Usage: node scripts/test-ria-transaction-report.mjs "assets/DOCS/ria julio 2026.pdf"
import fs from 'fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const pdfPath = process.argv[2] || 'assets/DOCS/ria julio 2026.pdf';
const html = fs.readFileSync('pages/reports.html', 'utf8');

function extractMethod(name) {
    // Match the method definition (8-space indent inside the Reports object literal)
    const defRe = new RegExp(`^        ${name}\\((?:[^)]*)\\)\\s*\\{`, 'm');
    const match = html.match(defRe);
    if (!match) throw new Error(`method definition ${name} not found`);
    const start = match.index + 8;
    const bodyStart = html.indexOf('{', start);
    let depth = 0;
    for (let i = bodyStart; i < html.length; i++) {
        if (html[i] === '{') depth++;
        else if (html[i] === '}') {
            depth--;
            if (depth === 0) return html.slice(start, i + 1);
        }
    }
    throw new Error(`unbalanced braces for ${name}`);
}

const bound = {};
// eslint-disable-next-line no-new-func
bound.parseBarriDate = new Function(`return function ${extractMethod('parseBarriDate')}`)().bind(bound);
// eslint-disable-next-line no-new-func
bound.parseRiaTransactionReport = new Function(`return function ${extractMethod('parseRiaTransactionReport')}`)().bind(bound);

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
    return [...lineMap.entries()].sort((a, b) => b[0] - a[0]).map(([, rowItems]) => {
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

const data = new Uint8Array(fs.readFileSync(pdfPath));
const pdf = await getDocument({ data, useSystemFonts: true }).promise;
let allLines = [];
for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    const content = await page.getTextContent();
    allLines = allLines.concat(extractLines(content.items));
}

const result = bound.parseRiaTransactionReport(allLines);

const giros = result.transactions.filter(t => t.type === 'giros');
const cheques = result.transactions.filter(t => t.type === 'cambio_cheque');
const sum = (arr, k) => Math.round(arr.reduce((s, t) => s + t[k], 0) * 100) / 100;

console.log('agency:', result.agency_number, '/', result.agency_name);
console.log('period:', result.date_from, '→', result.date_to);
console.log('company:', result.company);
console.log('txns:', result.transactions.length, `(giros=${giros.length}, cheques=${cheques.length})`);
console.log('giros principal sum:', sum(giros, 'principal'), 'fee:', sum(giros, 'fee'), 'tax:', sum(giros, 'tax'), 'total:', sum(giros, 'total'), 'comm:', sum(giros, 'agcomm'));
console.log('cheques principal sum:', sum(cheques, 'principal'), 'total:', sum(cheques, 'total'));
console.log('report totals:', result.totals);

let failures = 0;
function assert(label, actual, expected) {
    const pass = actual === expected;
    if (!pass) failures++;
    console.log(`${pass ? 'PASS' : 'FAIL'} ${label}: got ${actual}, expected ${expected}`);
}

// Expected values printed on the report itself
assert('agency_number', result.agency_number, 'TX3377');
assert('agency_name', result.agency_name, 'Anas Check Cashing');
assert('date_from', result.date_from, '2026-07-01');
assert('date_to', result.date_to, '2026-07-31');
assert('giros count', giros.length, 53);
assert('cheques count', cheques.length, 8);
assert('giros principal', sum(giros, 'principal'), 24464.15);
assert('giros tax', sum(giros, 'tax'), 242.33);
assert('giros fee', sum(giros, 'fee'), 525.00);
assert('giros total', sum(giros, 'total'), 25231.48);
assert('giros comm', sum(giros, 'agcomm'), 253.30);
assert('cheques total', sum(cheques, 'total'), -8897.08);
assert('totals.qty', result.totals.qty, 61);
assert('totals.principal', result.totals.principal, 15560.49);
assert('totals.total', result.totals.total, 16334.40);
assert('totals.agcomm', result.totals.agcomm, 253.30);

if (failures) {
    console.error(`\n${failures} assertion(s) failed`);
    process.exit(1);
}
console.log('\nAll assertions passed.');
