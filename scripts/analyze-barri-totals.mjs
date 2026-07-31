#!/usr/bin/env node
/** Analyze Barri Agency Activity PDF for document-reported vs computed totals. */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SAMPLE = join(ROOT, 'assets/DOCS/Report_Agency Activity abril -juni0 - Detailed_240247_07_07_2026 14_10_38.pdf');

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

const parseSignedMoney = (str) => {
    if (!str) return 0;
    const s = String(str).replace(/\s+/g, '').trim();
    if (s.includes('(')) {
        const paren = s.match(/\(\$?([\d,]+\.\d{2})\)?/);
        if (paren) return -(parseFloat(paren[1].replace(/,/g, '')) || 0);
    }
    const negative = s.startsWith('-');
    const val = parseFloat(s.replace(/[-$,()]/g, '')) || 0;
    return negative ? -val : val;
};

const data = readFileSync(SAMPLE);
const pdf = await getDocument({ data: new Uint8Array(data) }).promise;
let lines = [];
for (let i = 1; i <= pdf.numPages; i++) {
    lines = lines.concat(extractLines((await (await pdf.getPage(i)).getTextContent()).items));
}

const dailyRows = lines.filter((l) => /^Ending Balance \d{1,2}\/\d{1,2}\/\d{4} \(USD\)/i.test(l));
console.log('Daily Ending Balance rows (line extract):', dailyRows.length);

// Flatten all pages — daily subtotals survive better as space-joined page text
let flat = '';
for (let i = 1; i <= pdf.numPages; i++) {
    flat += (await (await pdf.getPage(i)).getTextContent()).items.map((it) => it.str).join(' ') + ' ';
}

const dailyFlatRe = /Ending Balance (\d{1,2}\/\d{1,2}\/\d{4}) \(USD\)\s+(\d+)\s+([\(\-\d,\.\s]+?)\s+([\d,\.\s]+?)\s+([\d,\.\s]+?)\s+([\(\-\d,\.\s]+?)\s+([\(\$\-\d,\.\s]+?)\s+([\d,\.\s]+?)\s+\$[\d,\.]+\s+\$[\d,\.]+/gi;
const flatRows = [...flat.matchAll(dailyFlatRe)];
console.log('Daily Ending Balance rows (flat text):', flatRows.length);

let sumQty = 0, sumP = 0, sumF = 0, sumTax = 0, sumTot = 0, sumAg = 0;
for (const m of flatRows) {
    sumQty += parseInt(m[2], 10);
    sumP += parseSignedMoney(m[3]);
    sumF += parseSignedMoney(m[4]);
    sumTax += parseSignedMoney(m[5]);
    sumTot += parseSignedMoney(m[6]);
    sumAg += parseSignedMoney(m[8]);
}

console.log('\nPeriod totals from daily Ending Balance rows (document subtotals):');
console.log('  Qty:', sumQty);
console.log('  Principal:', sumP.toFixed(2));
console.log('  Fee:', sumF.toFixed(2));
console.log('  Tax:', sumTax.toFixed(2));
console.log('  Total:', sumTot.toFixed(2));
console.log('  AgComm:', sumAg.toFixed(2));

if (flatRows[0]) {
    console.log('\nFirst day:', flatRows[0][1], 'qty', flatRows[0][2], 'principal', parseSignedMoney(flatRows[0][3]));
}
if (flatRows.at(-1)) {
    const last = flatRows.at(-1);
    console.log('Last day:', last[1], 'qty', last[2], 'closing balance', parseSignedMoney(last[7]));
}

// Compare with summing individual transactions (tail parser only)
function normalizeAgencyActivityLines(allLines) {
    const out = [];
    const headerRe = /^(DETAILED\s+AGENCY\s+ACTIVITY|Agency:|Currency|USD|To|From|Page\s+\d|Pay\s+Days|CST\/\s*CDT|Time)/i;
    const footerRe = /^(If you have any questions|--\s*\d+\s+of\s+\d+\s*--)/i;
    const colHeaderRe = /Transaction\s+Type\s+Reference/i;
    let agencyNum = '';
    for (const line of allLines.slice(0, 20)) {
        const mm = line.match(/^(\d{6})\s+[A-Z]/);
        if (mm) { agencyNum = mm[1]; break; }
    }
    let seenFirstAgency = false;
    for (const line of allLines) {
        const t = line.trim();
        if (!t || headerRe.test(t) || footerRe.test(t) || colHeaderRe.test(t)) continue;
        if (agencyNum && t.startsWith(agencyNum + ' ')) {
            if (seenFirstAgency) continue;
            seenFirstAgency = true;
            continue;
        }
        if (/^\d+\s+\w.*(?:AVE|ST|RD|BLVD|DR|LN|HWY|WAY|CT|PL|PKWY)\b/i.test(t) && /[A-Z]{2}\s*$/i.test(t)) continue;
        if (/^\d{1,2}\/\d{1,2}\/\d{2,4}$/.test(t)) continue;
        out.push(t);
    }
    const joined = [];
    for (let i = 0; i < out.length; i++) {
        const line = out[i];
        const isNewEntry = /^\d{1,2}:\d{2}\s/.test(line) || /^(Beginning|Ending)\s+Balance/i.test(line);
        if (isNewEntry || joined.length === 0) { joined.push(line); continue; }
        const prev = joined[joined.length - 1];
        const isTypeSuffix = /^(Electronico|Anulado)$/i.test(line.trim());
        if (isTypeSuffix && /Cambio Cheques\s+\d{6,}/i.test(prev)) {
            joined[joined.length - 1] = prev.replace(/(Cambio Cheques)\s+(\d{6,})/i, `$1 ${line.trim()} $2`);
        } else {
            joined[joined.length - 1] += ' ' + line;
        }
    }
    return joined.map((line) => line
        .replace(/\(\$?([\d,]+\.\d{2})\)?/g, '-$1')
        .replace(/\(\$?([\d,]+\.\d{2})/g, '-$1'));
}

function parseAgencyActivityTail(line) {
    const timeMatch = line.match(/^(\d{1,2}:\d{2})\s+/);
    if (!timeMatch) return null;
    let rest = line.slice(timeMatch[0].length).trim();
    rest = rest.replace(/\s+If you have any questions.*$/i, '').trim();
    const moneyTokens = [];
    let work = rest;
    while (work.length && moneyTokens.length < 9) {
        const mm = work.match(/(\(?-?\$?[\d,]+\.\d{2}\)?)\s*$/);
        if (mm) {
            moneyTokens.unshift(mm[1]);
            work = work.slice(0, work.length - mm[0].length).trimEnd();
            continue;
        }
        if (moneyTokens.length >= 8) break;
        if (!/\s/.test(work)) break;
        work = work.replace(/\s+\S+\s*$/, '').trimEnd();
    }
    if (moneyTokens.length < 8) return null;
    const headMatch = work.match(/^(.+?)\s+(a\d{4,})\s+(\d+)(?:\s+(.+))?$/);
    if (!headMatch) return null;
    const left = headMatch[1].trim();
    const refMatch = left.match(/^(.*?)\s(\d{6,})(?:\s(.*))?$/);
    if (!refMatch) return null;
    return { amounts: moneyTokens };
}

function parseEndingBalanceSubtotalLine(line) {
    if (!/^Ending Balance \d{1,2}\/\d{1,2}\/\d{2,4} \(USD\)/i.test(line)) return null;
    const qtyMatch = line.match(/\(USD\)\s+(\d+)\s+/i);
    if (!qtyMatch) return null;
    const afterQty = line.slice(line.indexOf(qtyMatch[0]) + qtyMatch[0].length);
    const amounts = [...afterQty.matchAll(/-?\(?\$?[\d,]+\.\d{2}\)?/g)].map((m) => parseSignedMoney(m[0]));
    if (amounts.length < 6) return null;
    return {
        qty: parseInt(qtyMatch[1], 10),
        principal: amounts[0],
        fee: amounts[1],
        tax: amounts[2],
        total: amounts[3],
        balance: amounts[4],
        agcomm: amounts[5],
    };
}

const rawDaily = lines.filter((l) => /^Ending Balance \d{1,2}\/\d{1,2}\/\d{2,4} \(USD\)/i.test(l));
let rQty = 0, rP = 0, rF = 0, rTax = 0, rTot = 0, rAg = 0, rMiss = 0;
for (const line of rawDaily) {
    const d = parseEndingBalanceSubtotalLine(line);
    if (!d) { rMiss++; if (rMiss <= 3) console.warn('raw daily miss:', line.slice(0, 100)); continue; }
    rQty += d.qty; rP += d.principal; rF += d.fee; rTax += d.tax; rTot += d.total; rAg += d.agcomm;
}
console.log('\nRaw line daily subtotals:', rawDaily.length, 'parsed', rawDaily.length - rMiss, 'misses', rMiss);
console.log('Period from raw daily rows:', { qty: rQty, principal: rP.toFixed(2), fee: rF.toFixed(2), tax: rTax.toFixed(2), total: rTot.toFixed(2), agcomm: rAg.toFixed(2) });

const normalized = normalizeAgencyActivityLines(lines);

// Parse daily Ending Balance subtotals from normalized lines (document-reported daily aggregates)
const dailySubRe = /^Ending Balance (\d{1,2}\/\d{1,2}\/\d{2,4}) \(USD\)\s+(\d+)\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})/i;
const dailyFromNorm = normalized.filter((l) => /^Ending Balance/i.test(l));
console.log('\nNormalized Ending Balance lines:', dailyFromNorm.length);
console.log('Sample normalized ending:', dailyFromNorm[0]?.slice(0, 140));

let dQty = 0, dP = 0, dF = 0, dTax = 0, dTot = 0, dAg = 0, dMiss = 0;
for (const line of dailyFromNorm) {
    const m = line.match(dailySubRe);
    if (!m) { dMiss++; continue; }
    dQty += parseInt(m[2], 10);
    dP += parseSignedMoney(m[3]);
    dF += parseSignedMoney(m[4]);
    dTax += parseSignedMoney(m[5]);
    dTot += parseSignedMoney(m[6]);
    dAg += parseSignedMoney(m[8]);
}
console.log('Daily subtotal parse misses:', dMiss);
console.log('Period from normalized daily rows:', { qty: dQty, principal: dP.toFixed(2), fee: dF.toFixed(2), tax: dTax.toFixed(2), total: dTot.toFixed(2), agcomm: dAg.toFixed(2) });

let txCount = 0, txP = 0, txTot = 0, txAg = 0;
for (const line of normalized) {
    if (/^(Beginning|Ending)\s+Balance/i.test(line)) continue;
    const p = parseAgencyActivityTail(line);
    if (p) {
        txCount++;
        txP += parseSignedMoney(p.amounts[0]);
        txTot += parseSignedMoney(p.amounts[3]);
        txAg += parseSignedMoney(p.amounts[5]);
    }
}
console.log('\nSummed from individual txn lines (NOT document period totals):');
console.log('  Count:', txCount);
console.log('  Principal:', txP.toFixed(2));
console.log('  Total:', txTot.toFixed(2));
console.log('  AgComm:', txAg.toFixed(2));
