<?php

/** Roles that may mark ledger entries paid or reopen them. */
function ledger_can_settle_user(array $user): bool {
    return in_array($user['role'] ?? '', ['admin', 'manager'], true);
}

function ledger_valid_status_filter(string $status): bool {
    return in_array($status, ['open', 'paid', 'all'], true);
}

/** @return string|null Error message when entry cannot be marked paid; null when allowed. */
function ledger_mark_paid_error(array $entry): ?string {
    if (($entry['status'] ?? 'open') !== 'open') {
        return 'Entry is already paid';
    }
    $owed = (float)($entry['owed_to_store'] ?? 0);
    $owes = (float)($entry['store_owes'] ?? 0);
    if ($owed <= 0 && $owes <= 0) {
        return 'Entry has no balance to mark as paid';
    }
    return null;
}

/** @return string|null Error message when entry cannot be reopened; null when allowed. */
function ledger_reopen_error(array $entry): ?string {
    if (($entry['status'] ?? 'open') === 'paid') {
        return null;
    }
    return 'Entry is not paid';
}

function ledger_entry_is_open(array $entry): bool {
    return ($entry['status'] ?? 'open') === 'open';
}

function ledger_open_status_sql(string $alias = 'sl'): string {
    return " AND {$alias}.status = 'open'";
}
