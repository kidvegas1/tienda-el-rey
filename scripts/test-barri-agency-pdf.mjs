#!/usr/bin/env node
/**
 * Regression: Barri Detailed Agency Activity PDF (240247, Apr–Jun 2026).
 * Keep in sync with parseAgencyActivityFormat in pages/reports.html.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
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

const parseBarriDate = (str) => {
    const parts = String(str || '').match(/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
    if (!parts) return '';
    let year = parts[3];
    if (year.length === 2) year = (parseInt(year, 10) > 50 ? '19' : '20') + year;
    return `${year}-${parts[1].padStart(2, '0')}-${parts[2].padStart(2, '0')}`;
};

const parseSignedMoney = (str) => {
    if (!str) return 0;
    const s = String(str).trim();
    if (s.includes('(')) {
        const paren = s.match(/\(\$?([\d,]+\.\d{2})\)?/);
        if (paren) return -(parseFloat(paren[1].replace(/,/g, '')) || 0);
    }
    const negative = s.startsWith('-');
    const val = parseFloat(s.replace(/[-$,()]/g, '')) || 0;
    return negative ? -val : val;
};

const extractAccountingAmount = (text) => {
    if (!text) return null;
    const s = String(text);
    const paren = s.match(/\(\$?([\d,]+\.\d{2})\)?/);
    if (paren) return -(parseFloat(paren[1].replace(/,/g, '')) || 0);
    const neg = s.match(/-\$?([\d,]+\.\d{2})/);
    if (neg) return -(parseFloat(neg[1].replace(/,/g, '')) || 0);
    const pos = s.match(/\$([\d,]+\.\d{2})/);
    if (pos) return parseFloat(pos[1].replace(/,/g, '')) || 0;
    return null;
};

function normalizeAgencyActivityLines(lines) {
    const out = [];
    const headerRe = /^(DETAILED\s+AGENCY\s+ACTIVITY|Agency:|Currency|USD|To|From|Page\s+\d|Pay\s+Days|CST\/\s*CDT|Time)/i;
    const footerRe = /^(If you have any questions|--\s*\d+\s+of\s+\d+\s*--)/i;
    const colHeaderRe = /Transaction\s+Type\s+Reference/i;
    let agencyNum = '';
    for (const line of lines.slice(0, 20)) {
        const m = line.match(/^(\d{6})\s+[A-Z]/);
        if (m) { agencyNum = m[1]; break; }
    }
    let seenFirstAgency = false;
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line || headerRe.test(line) || footerRe.test(line) || colHeaderRe.test(line)) continue;
        if (agencyNum && line.startsWith(agencyNum + ' ')) {
            if (seenFirstAgency) continue;
            seenFirstAgency = true; continue;
        }
        if (/^\d+\s+\w.*(?:AVE|ST|RD|BLVD|DR|LN|HWY|WAY|CT|PL|PKWY)\b/i.test(line) && /[A-Z]{2}\s*$/i.test(line)) continue;
        if (/^\d{1,2}\/\d{1,2}\/\d{2,4}$/.test(line)) continue;
        out.push(line);
    }
    const joined = [];
    for (let i = 0; i < out.length; i++) {
        const line = out[i];
        const isNewEntry = /^\d{1,2}:\d{2}\s/.test(line) || /^(Beginning|Ending)\s+Balance/i.test(line);
        if (isNewEntry || joined.length === 0) {
            joined.push(line);
            continue;
        }
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
        const m = work.match(/(\(?-?\$?[\d,]+\.\d{2}\)?)\s*$/);
        if (m) {
            moneyTokens.unshift(m[1]);
            work = work.slice(0, work.length - m[0].length).trimEnd();
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
    return { type: refMatch[1].trim(), reference: refMatch[2], amounts: moneyTokens };
}

function parseAgencyActivityFormat(lines) {
    const result = { agency_number: '', date_from: '', date_to: '', beginning_balance: 0, ending_balance: 0, transactions: [] };
    for (let j = 0; j < Math.min(lines.length, 25); j++) {
        const line = lines[j].trim();
        const headerRange = line.match(/(\d{1,2}\/\d{1,2}\/\d{2,4})\s+(\d{1,2}\/\d{1,2}\/\d{2,4})/);
        if (headerRange && (/USD/i.test(line) || /MULTISERVICES|SULY/i.test(line) || /^\d{5,6}\s/.test(line))) {
            result.date_from = parseBarriDate(headerRange[1]);
            result.date_to = parseBarriDate(headerRange[2]);
            break;
        }
    }
    for (const line of lines) {
        if (!/Beginning\s+Balance/i.test(line)) continue;
        const amt = extractAccountingAmount(line);
        if (amt !== null && amt > 0) { result.beginning_balance = amt; break; }
    }
    const normalized = normalizeAgencyActivityLines(lines);
    for (const line of normalized) {
        const p = parseAgencyActivityTail(line);
        if (p) result.transactions.push(p);
    }
    for (let j = lines.length - 1; j >= 0; j--) {
        if (!/Ending\s+Balance/i.test(lines[j])) continue;
        const chunk = [lines[j], lines[j + 1] || ''].join(' ');
        const amt = extractAccountingAmount(chunk);
        if (amt !== null) { result.ending_balance = amt; break; }
    }
    return result;
}

const data = readFileSync(SAMPLE);
const pdf = await getDocument({ data: new Uint8Array(data) }).promise;
let allLines = [];
for (let i = 1; i <= pdf.numPages; i++) {
    allLines = allLines.concat(extractLines((await (await pdf.getPage(i)).getTextContent()).items));
}

const parsed = parseAgencyActivityFormat(allLines);
console.log('From:', parsed.date_from, 'To:', parsed.date_to);
console.log('Beginning:', parsed.beginning_balance, 'Ending:', parsed.ending_balance);
console.log('Transactions:', parsed.transactions.length);

const fail = (msg) => { console.error('FAIL:', msg); process.exit(1); };
if (parsed.date_from !== '2026-04-01') fail(`date_from ${parsed.date_from}`);
if (parsed.date_to !== '2026-06-30') fail(`date_to ${parsed.date_to}`);
if (Math.abs(parsed.beginning_balance - 44241.96) > 0.01) fail(`beginning ${parsed.beginning_balance}`);
if (Math.abs(parsed.ending_balance + 53083.57) > 0.01) fail(`ending ${parsed.ending_balance} expected -53083.57`);
if (parsed.transactions.length < 5800) fail(`txns ${parsed.transactions.length} expected >= 5800`);

// Agency activity must NOT use summed line principals as period totals
const balanceChange = Math.round((parsed.ending_balance - parsed.beginning_balance) * 100) / 100;
if (Math.abs(balanceChange + 97265.53) > 150) fail(`balance_change ${balanceChange} expected ~-97265.53`);

console.log('OK: Barri Agency Activity PDF regression passed');
