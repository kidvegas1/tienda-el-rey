import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import XLSX from 'xlsx';
import { createRequire } from 'module';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');
const require = createRequire(import.meta.url);

globalThis.XLSX = XLSX;
const { detectWorkbookPersonalTransfer } = require('../assets/js/personal-transfer-xlsx.js');

const sample = path.join(root, 'assets/DOCS/reporte personal guadalupe.xlsx');
if (!fs.existsSync(sample)) {
    console.error('Missing sample:', sample);
    process.exit(1);
}

const wb = XLSX.read(fs.readFileSync(sample), { type: 'buffer' });
const parsed = detectWorkbookPersonalTransfer(wb, XLSX);

if (!parsed || parsed.format !== 'personal_viamericas') {
    console.error('Expected personal_viamericas format, got', parsed?.format);
    process.exit(1);
}
if (parsed.agency_number !== 'A10556') {
    console.error('Expected agency A10556, got', parsed.agency_number);
    process.exit(1);
}
if (parsed.transactions.length < 2400) {
    console.error('Expected >= 2500 transactions, got', parsed.transactions.length);
    process.exit(1);
}

const first = parsed.transactions[0];
if (!first.reference.startsWith('A10556-') || !first.sender_name || !first.beneficiary) {
    console.error('Bad first row:', first);
    process.exit(1);
}

console.log('Agency:', parsed.agency_number);
console.log('Dates:', parsed.date_from, '→', parsed.date_to);
console.log('Transactions:', parsed.transactions.length);
console.log('Total USD:', parsed.totals.principal.toFixed(2));
console.log('OK: personal transfer xlsx regression passed');
