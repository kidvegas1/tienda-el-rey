/**
 * Personal / user-created transfer Excel exports (headerless Viamericas-style rows
 * and Reports Center TRANSFERENCIAS sheets).
 */
(function (root, factory) {
    const api = factory(root.XLSX);
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.PersonalTransferXlsx = api;
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function (XLSX) {
    const REF_PATTERN = /^A\d{4,6}-\d+$/i;

    function str(v) {
        return String(v ?? '').trim();
    }

    function parseMoney(val) {
        const s = str(val);
        if (!s) return { amount: 0, currency: 'USD' };
        const currency = /MXN/i.test(s) ? 'MXN' : 'USD';
        const amount = parseFloat(s.replace(/[^0-9.\-]/g, '')) || 0;
        return { amount, currency };
    }

    function parseExcelDate(val, xlsx) {
        if (val == null || val === '') return null;
        if (typeof val === 'number' && xlsx?.SSF?.parse_date_code) {
            const d = xlsx.SSF.parse_date_code(val);
            if (d) {
                const hh = String(d.H || 0).padStart(2, '0');
                const mi = String(d.M || 0).padStart(2, '0');
                const ss = String(d.S || 0).padStart(2, '0');
                return `${d.y}-${String(d.m).padStart(2, '0')}-${String(d.d).padStart(2, '0')} ${hh}:${mi}:${ss}`.trim();
            }
        }
        const s = str(val);
        const m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
        if (m) {
            const [, dd, mm, yyyy, hh = '00', mi = '00', ss = '00'] = m;
            return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')} ${hh.padStart(2, '0')}:${mi.padStart(2, '0')}:${ss.padStart(2, '0')}`;
        }
        return s || null;
    }

    function parseDestination(loc) {
        const parts = str(loc).split(/\s*-\s*/).map(p => p.trim()).filter(Boolean);
        return {
            destination_country: parts[0] || '',
            destination_city: parts.length >= 3 ? parts.slice(2).join(' - ') : (parts[parts.length - 1] || ''),
        };
    }

    function agencyFromRef(ref) {
        const m = str(ref).match(/^(A\d{4,6})-/i);
        return m ? m[1].toUpperCase() : '';
    }

    /** Headerless personal export: col A = A10556-12345, B sender, C beneficiary, ... */
    function detectPersonalTransferRows(rows) {
        if (!rows?.length) return false;
        let hits = 0;
        const sample = rows.slice(0, Math.min(25, rows.length));
        for (const row of sample) {
            if (REF_PATTERN.test(str(row[0]))) hits++;
        }
        return hits >= 3;
    }

    function parsePersonalTransferRows(rows, opts = {}) {
        const xlsx = opts.xlsx || XLSX;
        const transactions = [];
        let agencyNumber = '';

        for (const row of rows) {
            const reference = str(row[0]);
            if (!REF_PATTERN.test(reference)) continue;

            if (!agencyNumber) agencyNumber = agencyFromRef(reference);

            const usd = parseMoney(row[5]);
            const mxn = parseMoney(row[6]);
            const amountUsd = usd.currency === 'USD' ? usd.amount : (typeof row[5] === 'number' ? row[5] : usd.amount);
            const amountLocal = mxn.currency === 'MXN' ? mxn.amount : mxn.amount;
            if (amountUsd === 0 && amountLocal === 0) continue;

            const dest = parseDestination(row[8]);
            const dateSent = parseExcelDate(row[3], xlsx);
            const datePaid = parseExcelDate(row[4], xlsx);

            transactions.push({
                reference,
                transaction_code: reference,
                sender_name: str(row[1]),
                client_name: str(row[1]),
                beneficiary: str(row[2]),
                date_sent: dateSent,
                date_paid: datePaid,
                amount_usd: amountUsd,
                amount_local: amountLocal,
                currency: 'MXN',
                paying_bank: str(row[7]),
                destination_country: dest.destination_country,
                destination_city: dest.destination_city,
                company: 'Viamericas',
                transaction_type: 'money_transfer',
                source: 'personal_excel',
            });
        }

        const dates = transactions.map(t => (t.date_sent || '').slice(0, 10)).filter(Boolean).sort();
        const totalUsd = transactions.reduce((s, t) => s + (t.amount_usd || 0), 0);

        return {
            format: 'personal_viamericas',
            company: 'Viamericas',
            agency_number: agencyNumber,
            agency_name: '',
            date_from: dates[0] || '',
            date_to: dates[dates.length - 1] || dates[0] || '',
            transactions,
            totals: {
                qty: transactions.length,
                principal: totalUsd,
                amount_usd: totalUsd,
            },
        };
    }

    /** Reports Center TRANSFERENCIAS sheet with header row */
    function detectTransfersTemplateSheet(rows) {
        if (!rows?.length) return false;
        for (let i = 0; i < Math.min(rows.length, 30); i++) {
            const upper = rows[i].map(c => str(c).toUpperCase());
            if (upper.includes('FECHA') && upper.includes('CLIENTE') && (upper.includes('REFERENCIA') || upper.includes('BENEFICIARIO'))) {
                return true;
            }
        }
        return false;
    }

    function parseTransfersTemplateSheet(rows, opts = {}) {
        const xlsx = opts.xlsx || XLSX;
        let headerIdx = -1;
        const headers = [];

        for (let i = 0; i < Math.min(rows.length, 40); i++) {
            const upper = rows[i].map(c => str(c).toUpperCase());
            if (upper.includes('FECHA') && upper.includes('CLIENTE')) {
                headerIdx = i;
                rows[i].forEach(h => headers.push(str(h).toUpperCase()));
                break;
            }
        }
        if (headerIdx < 0) return null;

        const col = (name) => headers.findIndex(h => h === name || h.includes(name));
        const iFecha = col('FECHA');
        const iCliente = col('CLIENTE');
        const iBenef = col('BENEFICIARIO');
        const iComp = col('COMPANIA');
        const iRef = col('REFERENCIA');
        const iTipo = col('TIPO');
        const iUsd = headers.findIndex(h => h.includes('PRINCIPAL'));
        const iFee = col('FEE');
        const iTax = col('TAX');
        const iTotal = col('TOTAL');

        const transactions = [];
        for (let r = headerIdx + 1; r < rows.length; r++) {
            const row = rows[r];
            if (!row?.length) continue;
            const client = str(row[iCliente]);
            const ref = str(row[iRef]);
            const amt = parseFloat(str(row[iUsd]).replace(/[$,]/g, '')) || 0;
            if (!client && !ref) continue;
            if (/^TOTAL/i.test(client) || /^TOTALES/i.test(client)) continue;
            if (amt === 0 && !ref) continue;

            transactions.push({
                reference: ref,
                transaction_code: ref,
                sender_name: client,
                client_name: client,
                beneficiary: str(row[iBenef]),
                date_sent: parseExcelDate(row[iFecha], xlsx),
                date_paid: null,
                amount_usd: amt,
                fee: parseFloat(str(row[iFee]).replace(/[$,]/g, '')) || 0,
                tax: parseFloat(str(row[iTax]).replace(/[$,]/g, '')) || 0,
                total: parseFloat(str(row[iTotal]).replace(/[$,]/g, '')) || amt,
                company: str(row[iComp]) || '',
                transaction_type: str(row[iTipo]) || 'money_transfer',
                source: 'excel_template',
            });
        }

        const dates = transactions.map(t => (t.date_sent || '').slice(0, 10)).filter(Boolean).sort();
        return {
            format: 'transfers_template',
            company: transactions[0]?.company || '',
            agency_number: '',
            agency_name: '',
            date_from: dates[0] || '',
            date_to: dates[dates.length - 1] || '',
            transactions,
            totals: {
                qty: transactions.length,
                principal: transactions.reduce((s, t) => s + (t.amount_usd || 0), 0),
            },
        };
    }

    function detectWorkbookPersonalTransfer(wb, xlsx) {
        if (!wb?.SheetNames) return null;
        for (const name of wb.SheetNames) {
            const ws = wb.Sheets[name];
            const rows = xlsx.utils.sheet_to_json(ws, { header: 1, defval: '' });
            if (detectPersonalTransferRows(rows)) {
                const parsed = parsePersonalTransferRows(rows, { xlsx });
                parsed.sheetName = name;
                return parsed;
            }
            if (detectTransfersTemplateSheet(rows)) {
                const parsed = parseTransfersTemplateSheet(rows, { xlsx });
                if (parsed?.transactions?.length) {
                    parsed.sheetName = name;
                    return parsed;
                }
            }
        }
        return null;
    }

    return {
        detectPersonalTransferRows,
        parsePersonalTransferRows,
        detectTransfersTemplateSheet,
        parseTransfersTemplateSheet,
        detectWorkbookPersonalTransfer,
        parseExcelDate,
        parseMoney,
    };
}));
