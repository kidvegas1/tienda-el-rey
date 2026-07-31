import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const XLSX = require('xlsx');
require('../assets/js/check-cashing-xlsx.js');

const syntheticRows = [
    ['A19622', '07/31/2026 11:51:01 AM', 'ISSUER ONE', 'CUSTOMER ONE', '', '', 'Enviado', '1001', '$1,000.00', '$0.00', '$0.00', '111000001', '1000001', 'a19622'],
    ['A19622', '07/20/2026 01:05:00 PM', 'ISSUER TWO', 'CUSTOMER TWO', '', '', 'Enviado', '1002', '$3,000.00', '$0.00', '$0.00', '111000002', '1000002', 'a19622'],
    ['A19622', '07/10/2026 09:15:00 AM', 'ISSUER THREE', 'CUSTOMER THREE', '', '', 'Enviado', '1003', '$5,000.00', '$0.00', '$0.00', '111000003', '1000003', 'a19622'],
    ['A19622', '07/01/2026 08:00:00 AM', 'ISSUER FOUR', 'CUSTOMER FOUR', '', '', 'Enviado', '1004', '$8,000.00', '$0.00', '$0.00', '111000004', '1000004', 'a19622'],
    ['A19622', '07/15/2026 08:00:00 AM', 'DELETED ISSUER', 'DELETED CUSTOMER', '', '', 'Cheque eliminado', '1005', '$2,500.00', '$0.00', '$0.00', '111000005', '1000005', 'a19622'],
    [],
    ['MONTO TOTAL', '$17,000.00'],
    ['CHEQUE(S)', '5'],
];
const worksheet = XLSX.utils.aoa_to_sheet(syntheticRows);
const workbook = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(workbook, worksheet, 'organization');
const parsed = globalThis.CheckCashingXlsx.detectWorkbook(workbook, XLSX);

assert.ok(parsed, 'organization.xlsx should be auto-detected');
assert.equal(parsed.format, 'viamericas_check_cashing');
assert.equal(parsed.company, 'Viamericas');
assert.equal(parsed.agency_number, 'A19622');
assert.equal(parsed.date_from, '2026-07-01');
assert.equal(parsed.date_to, '2026-07-31');
assert.equal(parsed.deleted_count, 1, 'deleted check must be excluded');
assert.equal(parsed.transactions.length, 4);
assert.equal(parsed.totals.amount, 17000);
assert.equal(parsed.totals.commission, 540);

const tierCounts = parsed.transactions.reduce((counts, tx) => {
    const label = `${tx.commission_rate * 100}%`;
    counts[label] = (counts[label] || 0) + 1;
    assert.equal(tx.transaction_type, 'cambio_cheque');
    assert.match(tx.reference, /^\d+-\S+-\d{8}$/);
    return counts;
}, {});
assert.deepEqual(tierCounts, { '1%': 1, '2%': 1, '3%': 1, '4%': 1 });

// When the private source workbook is present locally, lock its real totals too.
// The file itself must not be committed because it contains customer/check data.
const privatePath = 'assets/DOCS/organization.xlsx';
if (fs.existsSync(privatePath)) {
    const real = globalThis.CheckCashingXlsx.detectWorkbook(XLSX.readFile(privatePath), XLSX);
    assert.ok(real, 'private organization.xlsx should be auto-detected');
    assert.equal(real.agency_number, 'A19622');
    assert.equal(real.date_from, '2026-07-01');
    assert.equal(real.date_to, '2026-07-31');
    assert.equal(real.deleted_count, 1);
    assert.equal(real.transactions.length, 185);
    assert.equal(real.totals.amount, 176378.39);
    assert.equal(real.totals.commission, 2058.68);
}

console.log('OK: Viamericas check-cashing XLSX parser passed');
