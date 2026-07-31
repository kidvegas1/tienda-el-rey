import fs from 'fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

function extractLines(items) {
    const yThreshold = 3;
    const lineMap = new Map();
    for (const item of items) {
        if (!item.str?.trim()) continue;
        const y = Math.round(item.transform[5]);
        let key = null;
        for (const k of lineMap.keys()) { if (Math.abs(k - y) <= yThreshold) { key = k; break; } }
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

const path = process.argv[2] || 'assets/DOCS/ESTADO DE CUENTA INTERMEX ABRIL -JUNIO 26.pdf';
const data = new Uint8Array(fs.readFileSync(path));
const pdf = await getDocument({ data, useSystemFonts: true }).promise;
let allLines = [];
for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    const content = await page.getTextContent();
    allLines = allLines.concat(extractLines(content.items));
}
console.log('pages:', pdf.numPages, 'lines:', allLines.length);
allLines.forEach((l, i) => console.log(String(i + 1).padStart(3), l.slice(0, 180)));
