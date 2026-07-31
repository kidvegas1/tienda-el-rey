import fs from 'fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

function extractLines(items) {
    const lineMap = new Map();
    for (const item of items) {
        if (!item.str?.trim()) continue;
        const y = Math.round(item.transform[5]);
        let key = null;
        for (const k of lineMap.keys()) { if (Math.abs(k - y) <= 3) { key = k; break; } }
        if (key === null) { key = y; lineMap.set(key, []); }
        lineMap.get(key).push(item);
    }
    return [...lineMap.entries()].sort((a, b) => b[0] - a[0]).map(([_, row]) => {
        const sorted = row.sort((a, b) => a.transform[4] - b.transform[4]);
        let result = '';
        for (let i = 0; i < sorted.length; i++) {
            if (i > 0) {
                const gap = sorted[i].transform[4] - (sorted[i - 1].transform[4] + (sorted[i - 1].width || 0));
                result += gap > 50 ? '\t' : ' ';
            }
            result += sorted[i].str;
        }
        return result.trim();
    }).filter(Boolean);
}

function parseMoney(s) {
    return parseFloat(String(s).replace(/[$,]/g, '')) || 0;
}

function parseIntermexGirosEnviados(lines) {
    const result = {
        agency_number: '', date_from: '', date_to: '',
        report_format: 'intermex_giros_enviados',
        finance_class: 'side_finances',
        transactions: [],
        totals: { principal: 0, agcomm: 0 },
    };
    const rowRe = /^(\d{4,6})\s+\$?([\d,]+\.\d{2})\s+\$?([\d,]+\.\d{2})\s+\$?([\d,]+\.\d{2})\s+\$?([\d,]+\.\d{2})\s+\$?([\d,]+\.\d{2})\s+([A-Z]{2})\s+\$?([\d,]+\.\d{2})/;
    for (const line of lines) {
        const title = line.match(/Reporte de Giros Enviados\s*-\s*(TX-?\d+)/i);
        if (title) result.agency_number = title[1].replace(/-/g, '').toUpperCase();
        const dr = line.match(/Desde\s+(\d{1,2}\/\d{1,2}\/\d{4})\s+a\s+(\d{1,2}\/\d{1,2}\/\d{4})/i);
        if (dr) { result.date_from = dr[1]; result.date_to = dr[2]; }
        const m = line.match(rowRe);
        if (!m) continue;
        const principal = parseMoney(m[2]);
        const total = parseMoney(m[5]);
        if (principal === 0 && total === 0) continue;
        result.transactions.push({
            reference: `${result.agency_number || 'TX'}-${m[1]}`,
            principal,
            agcomm: parseMoney(m[8]),
            finance_class: 'side_finances',
        });
    }
    result.totals.principal = result.transactions.reduce((s, t) => s + t.principal, 0);
    result.totals.agcomm = result.transactions.reduce((s, t) => s + t.agcomm, 0);
    return result;
}

const path = process.argv[2] || 'assets/DOCS/ESTADO DE CUENTA INTERMEX ABRIL -JUNIO 26.pdf';
const data = new Uint8Array(fs.readFileSync(path));
const pdf = await getDocument({ data }).promise;
let lines = [];
for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    lines = lines.concat(extractLines((await page.getTextContent()).items));
}

const parsed = parseIntermexGirosEnviados(lines);
if (parsed.report_format !== 'intermex_giros_enviados') process.exit(1);
if (parsed.agency_number !== 'TX3144') {
    console.error('Expected TX3144, got', parsed.agency_number);
    process.exit(1);
}
if (parsed.transactions.length < 60) {
    console.error('Expected >= 60 txns, got', parsed.transactions.length);
    process.exit(1);
}
if (!parsed.transactions.every(t => t.finance_class === 'side_finances')) process.exit(1);

console.log('Agency:', parsed.agency_number);
console.log('Dates:', parsed.date_from, '→', parsed.date_to);
console.log('Transactions:', parsed.transactions.length);
console.log('Principal:', parsed.totals.principal.toFixed(2));
console.log('AgComm:', parsed.totals.agcomm.toFixed(2));
console.log('OK: Intermex Giros Enviados side finances regression passed');
