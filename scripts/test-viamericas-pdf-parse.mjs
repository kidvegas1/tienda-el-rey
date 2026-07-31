#!/usr/bin/env node
/**
 * Regression: Viamericas ViaRemote "Creación de Envíos" PDF (assets/DOCS sample).
 * Mirrors client parsers in pages/reports.html — keep in sync when changing parse logic.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const SAMPLE = join(ROOT, 'assets/DOCS/VIAMERICAS 3 MESES/ABR2026.pdf');

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
    if (!items.length) return [];
    const yThreshold = 3;
    const lineMap = new Map();
    for (const item of items) {
        const y = Math.round(item.transform[5]);
        let matched = false;
        for (const [key] of lineMap) {
            if (Math.abs(key - y) <= yThreshold) {
                lineMap.get(key).push(item);
                matched = true;
                break;
            }
        }
        if (!matched) lineMap.set(y, [item]);
    }
    const sortedKeys = [...lineMap.keys()].sort((a, b) => b - a);
    return sortedKeys.map((key) => {
        const lineItems = lineMap.get(key).sort((a, b) => a.transform[4] - b.transform[4]);
        let result = '';
        for (let i = 0; i < lineItems.length; i++) {
            const item = lineItems[i];
            if (i > 0) {
                const prevItem = lineItems[i - 1];
                const prevEnd = prevItem.transform[4] + (prevItem.width || 0);
                const gap = item.transform[4] - prevEnd;
                result += gap > 50 ? '\t' : ' ';
            }
            result += item.str;
        }
        return result.trim();
    }).filter((l) => l.length > 0);
}

function parseViamericasCreacionEnviosRows(lines) {
    const result = {
        agency_number: '',
        operator_number: '',
        date_from: '',
        date_to: '',
        company: 'Viamericas',
        transactions: [],
    };

    const rowStartRe = /^(A\d{4,6})\s*-\s*(.+)$/;
    const operatorRe = /(SULY\d+)\s*(Pagado|Cancelado|Anulado)\s*/i;
    const txnNumOnlyRe = /^-?(\d{4,6})$/;
    const txnNumTailRe = /^(-?\d{4,6})[\t\s]+(.+)$/;
    const skipLineRe = /^(Inicio |Estado de|Detalle de|Historial de|Centro de|Desde$|Hasta$|Agencia$|Cajero$|Envios$|Modo|Generar|Creación|Nro\.|Transacción$|Cliente$|Beneficiario$|Cajero |Pago$|\$[\d,]+||%|\d+$)/i;

    for (let i = 0; i < lines.length; i++) {
        const t = lines[i].trim();
        if (t === 'Desde' && i + 1 < lines.length) result.date_from = parseBarriDate(lines[i + 1].trim());
        if (t === 'Hasta' && i + 1 < lines.length) result.date_to = parseBarriDate(lines[i + 1].trim());
        if (t === 'Agencia' && i + 1 < lines.length) {
            const ag = lines[i + 1].trim().match(/^(A\d{4,6})$/i);
            if (ag) result.agency_number = ag[1].toUpperCase();
        }
    }

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line || skipLineRe.test(line)) continue;

        const startMatch = line.match(rowStartRe);
        if (!startMatch) continue;

        const agencyPrefix = startMatch[1].toUpperCase();
        const body = startMatch[2].trim();
        if (!body) continue;
        const opMatch = body.match(operatorRe);
        if (!opMatch) continue;

        let afterOp = body.substring(opMatch.index + opMatch[0].length).trim();
        afterOp = afterOp.replace(/^[A-Z]\s+/i, '').trim();
        const operator = opMatch[1].toUpperCase();
        const txnStatus = opMatch[2];

        const amounts = [];
        const negRe = /\(\$?([\d,]+\.\d{2})\)/g;
        let negM;
        while ((negM = negRe.exec(afterOp)) !== null) amounts.push(parseMoney(negM[1]));
        if (amounts.length === 0) {
            const posRe = /\$([\d,]+\.\d{2})/g;
            let posM;
            while ((posM = posRe.exec(afterOp)) !== null) amounts.push(parseMoney(posM[1]));
        }
        const principal = amounts[0] || 0;
        const total = amounts[3] || principal;
        if (principal === 0 && total === 0) continue;

        let reference = '';
        let j = i + 1;
        while (j < lines.length && j <= i + 2) {
            const next = lines[j].trim();
            if (!next) { j++; continue; }
            if (rowStartRe.test(next)) break;
            const tailMatch = next.match(txnNumTailRe);
            if (tailMatch) {
                reference = `${agencyPrefix}-${tailMatch[1].replace(/^-/, '')}`;
                j++;
                break;
            }
            if (txnNumOnlyRe.test(next)) {
                reference = `${agencyPrefix}-${next.replace(/^-/, '')}`;
                j++;
                break;
            }
            break;
        }
        if (!reference) continue;

        if (!result.agency_number) result.agency_number = agencyPrefix;
        if (!result.operator_number) result.operator_number = operator;

        result.transactions.push({
            reference,
            operator,
            principal,
            total,
            transaction_status: txnStatus,
        });
        i = j - 1;
    }

    return result;
}

function enrichParsedAgencyMetadata(parsed, lines) {
    if (!parsed?.transactions?.length) return parsed;
    if (!parsed.operator_number) {
        for (const tx of parsed.transactions) {
            if (/^SULY\d+$/i.test(tx.operator || '')) {
                parsed.operator_number = tx.operator.toUpperCase();
                break;
            }
        }
    }
    return parsed;
}

function assert(cond, msg) {
    if (!cond) {
        console.error('FAIL:', msg);
        process.exit(1);
    }
}

const data = readFileSync(SAMPLE);
const pdf = await getDocument({ data: new Uint8Array(data) }).promise;

let allLines = [];
for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const content = await page.getTextContent();
    allLines = allLines.concat(extractLines(content.items));
}

const parsed = enrichParsedAgencyMetadata(parseViamericasCreacionEnviosRows(allLines), allLines);

console.log('Lines extracted:', allLines.length);
console.log('Agency:', parsed.agency_number, 'Operator:', parsed.operator_number);
console.log('Transactions:', parsed.transactions.length);
console.log('Date range:', parsed.date_from, '→', parsed.date_to);

assert(parsed.agency_number === 'A22592', `expected agency A22592, got ${parsed.agency_number}`);
assert(parsed.operator_number === 'SULY2022', `expected operator SULY2022, got ${parsed.operator_number}`);
assert(parsed.transactions.length >= 85, `expected >= 85 transactions, got ${parsed.transactions.length}`);
assert(parsed.date_from === '2026-04-01', `expected date_from 2026-04-01, got ${parsed.date_from}`);
assert(parsed.date_to === '2026-04-30', `expected date_to 2026-04-30, got ${parsed.date_to}`);

const refs = new Set(parsed.transactions.map((t) => t.reference));
assert(refs.has('A22592-12866'), 'missing sample ref A22592-12866');

const cancel = parsed.transactions.find((t) => t.reference === 'A22592-12892');
assert(cancel && cancel.transaction_status === 'Cancelado', 'expected cancelled txn A22592-12892');

// Browser PDF.js often joins ref tail with spaces instead of tabs
function extractLinesSpaceOnly(items) {
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
        return lineMap.get(key).sort((a, b) => a.transform[4] - b.transform[4]).map((i) => i.str).join(' ').trim();
    }).filter((l) => l.length > 0);
}

let spaceLines = [];
for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const content = await page.getTextContent();
    spaceLines = spaceLines.concat(extractLinesSpaceOnly(content.items));
}
const spaceParsed = parseViamericasCreacionEnviosRows(spaceLines);
assert(spaceParsed.transactions.length >= 85, `space-only lines: expected >= 85 txns, got ${spaceParsed.transactions.length}`);
const spacePrincipal = spaceParsed.transactions.reduce((s, t) => s + t.principal, 0);
assert(spacePrincipal >= 60000, `space-only lines: expected >= 60k principal, got ${spacePrincipal}`);

console.log('OK: Viamericas ABR2026.pdf parse regression passed');
