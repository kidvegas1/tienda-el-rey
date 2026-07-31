(function (global) {
    'use strict';

    const CheckCashingXlsx = {
        parseMoney(value) {
            return Number(String(value ?? '').replace(/[$,\s]/g, '')) || 0;
        },

        parseDateTime(value) {
            const match = String(value ?? '').trim().match(
                /^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2}):(\d{2})\s*(AM|PM))?/i
            );
            if (!match) return null;
            let hour = Number(match[4] || 0);
            const meridiem = String(match[7] || '').toUpperCase();
            if (meridiem === 'PM' && hour < 12) hour += 12;
            if (meridiem === 'AM' && hour === 12) hour = 0;
            const pad = (n) => String(n).padStart(2, '0');
            return {
                date: `${match[3]}-${pad(match[1])}-${pad(match[2])}`,
                time: `${pad(hour)}:${match[5] || '00'}:${match[6] || '00'}`,
            };
        },

        commissionRate(amount) {
            if (amount <= 0) return 0;
            if (amount <= 2000) return 0.01;
            if (amount <= 4000) return 0.02;
            if (amount <= 7000) return 0.03;
            return 0.04;
        },

        isViamericasCheckSheet(rows, sheetName = '') {
            const candidates = (rows || []).slice(0, 30).filter((row) => {
                const date = this.parseDateTime(row?.[1]);
                const amount = this.parseMoney(row?.[8]);
                return /^A\d{4,}$/i.test(String(row?.[0] || '').trim())
                    && date
                    && amount > 0
                    && /^(Enviado|Cheque eliminado)$/i.test(String(row?.[6] || '').trim());
            });
            return candidates.length >= 3
                && (String(sheetName).toLowerCase().includes('organization') || candidates.length >= 8);
        },

        parseSheet(rows, sheetName = 'organization') {
            if (!this.isViamericasCheckSheet(rows, sheetName)) return null;

            const transactions = [];
            let deletedCount = 0;
            for (const row of rows) {
                const agencyNumber = String(row?.[0] || '').trim().toUpperCase();
                const dateTime = this.parseDateTime(row?.[1]);
                const status = String(row?.[6] || '').trim();
                const amount = this.parseMoney(row?.[8]);
                if (!/^A\d{4,}$/.test(agencyNumber) || !dateTime || amount <= 0) continue;
                if (/Cheque eliminado/i.test(status)) {
                    deletedCount++;
                    continue;
                }
                if (!/^Enviado$/i.test(status)) continue;

                const checkNumber = String(row?.[7] || '').trim();
                const routingNumber = String(row?.[11] || '').trim();
                const dateToken = dateTime.date.replace(/-/g, '');
                const reference = `${routingNumber}-${checkNumber}-${dateToken}`.slice(0, 30);
                const rate = this.commissionRate(amount);
                transactions.push({
                    agency_number: agencyNumber,
                    date_sent: `${dateTime.date} ${dateTime.time}`,
                    transaction_date: dateTime.date,
                    transaction_time: dateTime.time,
                    issuer: String(row?.[2] || '').trim().replace(/&amp;/gi, '&'),
                    customer_name: String(row?.[3] || '').trim(),
                    status,
                    check_number: checkNumber,
                    reference,
                    amount,
                    commission_rate: rate,
                    commission: Math.round(amount * rate * 100) / 100,
                    routing_number: routingNumber,
                    account_number: String(row?.[12] || '').trim(),
                    operator: String(row?.[13] || '').trim(),
                    company: 'Viamericas',
                    transaction_type: 'cambio_cheque',
                    source: 'excel_import',
                });
            }

            if (!transactions.length) return null;
            const dates = transactions.map((tx) => tx.transaction_date).sort();
            const agencyNumber = transactions[0].agency_number;
            return {
                format: 'viamericas_check_cashing',
                sheetName,
                agency_number: agencyNumber,
                company: 'Viamericas',
                date_from: dates[0],
                date_to: dates[dates.length - 1],
                deleted_count: deletedCount,
                transactions,
                totals: {
                    count: transactions.length,
                    amount: Math.round(transactions.reduce((sum, tx) => sum + tx.amount, 0) * 100) / 100,
                    commission: Math.round(transactions.reduce((sum, tx) => sum + tx.commission, 0) * 100) / 100,
                },
            };
        },

        detectWorkbook(workbook, XLSX) {
            if (!workbook || !XLSX) return null;
            for (const sheetName of workbook.SheetNames || []) {
                const rows = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], {
                    header: 1,
                    defval: '',
                    raw: false,
                });
                const parsed = this.parseSheet(rows, sheetName);
                if (parsed) return parsed;
            }
            return null;
        },
    };

    global.CheckCashingXlsx = CheckCashingXlsx;
    if (typeof module !== 'undefined' && module.exports) module.exports = CheckCashingXlsx;
})(typeof window !== 'undefined' ? window : globalThis);
