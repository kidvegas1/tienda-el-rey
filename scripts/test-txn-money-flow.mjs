/**
 * Smoke test for Barri product-summary money flow classification.
 * Run: node scripts/test-txn-money-flow.mjs
 */

const BARRI_OUT = [
  'Cambio Cheques Electronico',
  'Giro - Pago',
  'Pago Con Tarjeta',
  'Rembolso Payment',
];
const BARRI_IN = [
  'Bill Payment',
  'Comision Cancel',
  'Debitos Bancarios Cheque',
  'Giros',
  'Money Order',
  'Recarga Pinless',
];

function normalizeTxnTypeLabel(type) {
  return (type || '')
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ');
}

function isBarriMoneyOut(raw) {
  const n = normalizeTxnTypeLabel(raw);
  if (/giro\s*[-\/]\s*pago/.test(n)) return true;
  if (/cambio.*cheque|cheque.*electronico/.test(n)) return true;
  if (/^pago con tarjeta|^pago con targeta/.test(n)) return true;
  if (/^reembolso|^rembolso/.test(n)) return true;
  const outs = [
    'giro - pago', 'giro/pago', 'giro pago',
    'cambio cheques electronico', 'cambio cheques',
    'cambio de cheques electronico', 'cambio de cheques',
    'pago con tarjeta', 'pago con targeta',
    'reembolso', 'reembolsos', 'reembolso payment', 'rembolso payment',
  ];
  return outs.includes(n);
}

function isBarriMoneyIn(raw) {
  const n = normalizeTxnTypeLabel(raw);
  if (isBarriMoneyOut(raw)) return false;
  const ins = [
    'bill payment', 'pago facturas',
    'comision cancel', 'commission cancel',
    'debitos bancarios cheque', 'debito bancario',
    'giros', 'giro', 'money order',
    'recarga pinless', 'recargas pinless', 'recarga', 'recargas',
  ];
  if (ins.includes(n)) return true;
  if (/^recarga/.test(n)) return true;
  return false;
}

function txnMoneyFlow(tx, company = '') {
  const raw = normalizeTxnTypeLabel(tx.raw_type || tx.type || '');
  const co = (company || '').toLowerCase();
  if (co === 'barri' || co === '') {
    if (isBarriMoneyOut(raw)) return 'out';
    if (isBarriMoneyIn(raw)) return 'in';
  }
  return 'in';
}

let failed = 0;
for (const label of BARRI_OUT) {
  const flow = txnMoneyFlow({ raw_type: label }, 'Barri');
  if (flow !== 'out') {
    console.error(`FAIL: expected out for "${label}", got ${flow}`);
    failed++;
  }
}
for (const label of BARRI_IN) {
  const flow = txnMoneyFlow({ raw_type: label }, 'Barri');
  if (flow !== 'in') {
    console.error(`FAIL: expected in for "${label}", got ${flow}`);
    failed++;
  }
}

if (failed) {
  console.error(`\n${failed} classification failure(s)`);
  process.exit(1);
}
console.log('OK: Barri money flow classifications');
