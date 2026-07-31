import fs from 'fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const pdfPath = process.argv[2] || 'assets/DOCS/VIAMERICAS 3 MESES/DICIEMBRE 1.pdf';
const data = new Uint8Array(fs.readFileSync(pdfPath));
const pdf = await getDocument({ data, useSystemFonts: true }).promise;

function extractLines(items) {
    const yThreshold = 3;
    const lineMap = new Map();
    for (const item of items) {
        if (!item.str?.trim()) continue;
        const y = Math.round(item.transform[5]);
        let key = null;
        for (const k of lineMap.keys()) {
            if (Math.abs(k - y) <= yThreshold) { key = k; break; }
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

function parseMoney(s) {
    return parseFloat(String(s).replace(/[$,\s]/g, '')) || 0;
}

function parseBarriDate(s) {
    const m = String(s).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return '';
    return `${m[3]}-${m[1].padStart(2, '0')}-${m[2].padStart(2, '0')}`;
}

function parseViamericasCreacionEnviosRows(lines) {
    const result = { transactions: [], agency_number: '', date_from: '', date_to: '' };
    const rowStartRe = /^(A\d{4,6})\s*-\s*(.+)$/;
    const operatorRe = /(SULY\d+)\s+(Pagado|Cancelado|Anulado)\s+(\w)\s+/i;
    const txnNumOnlyRe = /^-?(\d{4,6})$/;
    const txnNumTailRe = /^(-?\d{4,6})\t(.+)$/;
    const skipLineRe = /^(Inicio |Estado de|Detalle de|Historial de|Centro de|Desde$|Hasta$|Agencia$|Cajero$|Envios$|Modo|Generar|Creación|Nro\.|Transacción$|Cliente$|Beneficiario$|Cajero |Pago$|\$[\d,]+||%|\d+$)/i;

    for (let i = 0; i < lines.length; i++) {
        const t = lines[i].trim();
        if (t === 'Desde' && i + 1 < lines.length) result.date_from = parseBarriDate(lines[i + 1].trim());
        if (t === 'Hasta' && i + 1 < lines.length) result.date_to = parseBarriDate(lines[i + 1].trim());
        if (t === 'Agencia' && i + 1 < lines.length) {
            const ag = lines[i + 1].trim().match(/^(A\d{4,6})$/);
            if (ag) result.agency_number = ag[1];
        }
    }

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line || skipLineRe.test(line)) continue;
        const startMatch = line.match(rowStartRe);
        if (!startMatch) continue;
        const agencyPrefix = startMatch[1];
        const body = startMatch[2].trim();
        const opMatch = body.match(operatorRe);
        if (!opMatch) continue;

        const beforeOp = body.substring(0, opMatch.index).trim();
        const afterOp = body.substring(opMatch.index + opMatch[0].length).trim();

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

        const tabParts = beforeOp.split('\t').map(c => c.trim()).filter(Boolean);
        const customer = tabParts[0] || 'Unknown';

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
        i = j - 1;
        if (!result.agency_number) result.agency_number = agencyPrefix;
        result.transactions.push({ reference, principal, customer, status: opMatch[2] });
    }
    return result;
}

function parseViamericasViaRemoteRows(lines) {
    const result = { transactions: [], agency_number: '' };
    const rowStartRe = /^A\d{4,6}\s*-\s*(.+)$/;
    const continuationRe = /^(\d{1,4})\s+(.*)$/;
    const skipLineRe = /^(Inicio |Buscar |Producto:|Money Transfer|Nro\.|Transacci|Status|Procesar|Cheques|Reportes|Buscar nombre|\$[\d,]+||%)/i;
    const uiNoiseRe = /(Imprimir|Modificar|Cancelar|Ver más|Ya Modificada|Modificación|no disponible||||)/gi;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line || skipLineRe.test(line)) continue;
        const startMatch = line.match(rowStartRe);
        if (!startMatch) continue;
        const agencyPrefix = line.match(/^(A\d{4,6})/)[1];
        let body = startMatch[1].trim();
        if (!/\d{1,2}\/\d{1,2}\//.test(body) && !body.includes('\t') && !/[\d,]+\.\d{2}/.test(body)) continue;

        let continuation = '';
        if (i + 1 < lines.length) {
            const next = lines[i + 1].trim();
            if (continuationRe.test(next)) {
                continuation = next;
                i++;
            }
        }
        if (!continuation) continue;

        const contMatch = continuation.match(continuationRe);
        const reference = `${agencyPrefix}-${contMatch[1]}`;
        let contRest = contMatch[2].replace(uiNoiseRe, '').split('\t')[0].trim();

        let namePart1 = body;
        let dataPart = '';
        if (body.includes('\t')) {
            const tabParts = body.split('\t');
            namePart1 = tabParts[0].trim();
            dataPart = tabParts.slice(1).join(' ').trim();
        } else {
            const dateIdx = body.search(/\d{1,2}\/\d{1,2}\/\d{2,4}/);
            if (dateIdx > 0) {
                namePart1 = body.substring(0, dateIdx).trim();
                dataPart = body.substring(dateIdx).trim();
            } else dataPart = body;
        }

        dataPart = dataPart.replace(uiNoiseRe, '').trim();
        const amounts = dataPart.match(/([\d,]+\.\d{2})/g) || [];
        const principal = amounts[0] ? parseMoney(amounts[0]) : 0;
        if (principal === 0) continue;

        if (!result.agency_number) result.agency_number = agencyPrefix;
        result.transactions.push({ reference, principal, customer: namePart1 });
    }
    return result;
}

function parseViamericasFormat(lines) {
    const creacion = parseViamericasCreacionEnviosRows(lines);
    if (creacion.transactions.length > 0) return { ...creacion, parser: 'creacion' };
    const viaRemote = parseViamericasViaRemoteRows(lines);
    if (viaRemote.transactions.length > 0) return { ...viaRemote, parser: 'viaremote' };
    return { transactions: [], agency_number: '', parser: 'none' };
}

const allLines = [];
for (let p = 1; p <= pdf.numPages; p++) {
    const items = (await pdf.getPage(p).then((pg) => pg.getTextContent())).items;
    allLines.push(...extractLines(items));
}

const parsed = parseViamericasFormat(allLines);
console.log('FILE:', pdfPath);
console.log('PARSER:', parsed.parser);
console.log('LINES:', allLines.length, 'TXNS:', parsed.transactions.length, 'AGENCY:', parsed.agency_number);
if (parsed.date_from) console.log('RANGE:', parsed.date_from, 'to', parsed.date_to);
console.log('SUM:', parsed.transactions.reduce((s, t) => s + t.principal, 0).toFixed(2));
console.log('SAMPLE:', parsed.transactions.slice(0, 3));
