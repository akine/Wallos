<?php

/*
 * Variable monthly pricing for subscriptions whose bill changes every month
 * (electricity, mobile, gas, water).
 *
 * has_variable_price = 0 leaves every existing calculation untouched.
 * has_variable_price = 1 stores one actual amount per calendar month. Totals
 * use that month's actual, then the most recent actual, then subscriptions.price.
 * The amount is already a month's cost, so it is not divided or multiplied by
 * the billing cycle. Fixed-price subscriptions keep using subscriptions.price.
 */

function subscription_has_variable_price(array $subscription): bool
{
    return !empty($subscription['has_variable_price']);
}

function subscription_price_month(array $subscription, ?string $fallback = null): string
{
    $candidate = '';
    if (!empty($subscription['next_payment'])) {
        $candidate = substr((string) $subscription['next_payment'], 0, 7);
    }
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $candidate)) {
        return $candidate;
    }

    return $fallback ?? date('Y-m');
}

/**
 * subscription id => [ 'YYYY-MM' => price ].
 * Pass null to load every account (admin "all users" API only).
 *
 * @return array<int, array<string, float>>
 */
function load_price_history_index(?SQLite3 $db, ?int $userId): array
{
    $index = [];
    if ($db === null) {
        return $index;
    }

    if ($userId === null) {
        $stmt = $db->prepare('SELECT subscription_id, period, price FROM subscription_price_history');
    } else {
        $stmt = $db->prepare(
            'SELECT h.subscription_id, h.period, h.price
             FROM subscription_price_history h
             INNER JOIN subscriptions s ON s.id = h.subscription_id
             WHERE s.user_id = :userId'
        );
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    }

    if ($stmt === false) {
        return $index;
    }

    $result = $stmt->execute();
    if (!$result) {
        return $index;
    }

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $index[(int) $row['subscription_id']][$row['period']] = (float) $row['price'];
    }

    return $index;
}

/**
 * @return array<int, array{period: string, price: float, note: string}>
 */
function price_history_for_subscription(?SQLite3 $db, int $subscriptionId): array
{
    $history = [];
    if ($db === null) {
        return $history;
    }
    $stmt = $db->prepare(
        'SELECT period, price, note FROM subscription_price_history
         WHERE subscription_id = :sid ORDER BY period ASC'
    );
    if ($stmt === false) {
        return $history;
    }
    $stmt->bindValue(':sid', $subscriptionId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) {
        return $history;
    }

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $history[] = [
            'period' => $row['period'],
            'price' => (float) $row['price'],
            'note' => $row['note'] ?? '',
        ];
    }

    return $history;
}

/**
 * Unconverted amount for $yearMonth.
 * Variable subscriptions: that month, else the latest stored month, else the base price.
 */
function effective_subscription_price(array $subscription, string $yearMonth, array $historyIndex): float
{
    $base = (float) ($subscription['price'] ?? 0);
    if (!subscription_has_variable_price($subscription)) {
        return $base;
    }

    $entries = $historyIndex[(int) ($subscription['id'] ?? 0)] ?? [];
    if (isset($entries[$yearMonth])) {
        return (float) $entries[$yearMonth];
    }
    if ($entries) {
        $periods = array_keys($entries);
        rsort($periods, SORT_STRING);
        return (float) $entries[$periods[0]];
    }

    return $base;
}

/**
 * Decode the form payload. null means "leave stored history alone".
 * An array (possibly empty) is the full replacement set.
 *
 * @return array<int, array{period: string, price: float, note: string}>|null
 */
function parse_price_history_json($json): ?array
{
    if (!is_string($json) || $json === '' || strlen($json) > 100000) {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }

    $byPeriod = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $period = isset($entry['period']) ? (string) $entry['period'] : '';
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            continue;
        }
        if (!isset($entry['price']) || !is_numeric($entry['price'])) {
            continue;
        }
        $price = (float) $entry['price'];
        if (!is_finite($price)) {
            continue;
        }

        $note = isset($entry['note']) ? trim(strip_tags((string) $entry['note'])) : '';
        $note = preg_replace('/[\x00-\x1F\x7F]/u', '', $note) ?? '';
        $note = mb_substr($note, 0, 200);

        $byPeriod[$period] = [
            'period' => $period,
            'price' => $price,
            'note' => $note,
        ];
        if (count($byPeriod) >= 240) {
            break;
        }
    }

    ksort($byPeriod);

    return array_values($byPeriod);
}

function replace_subscription_price_history(?SQLite3 $db, int $subscriptionId, array $entries): bool
{
    if ($db === null) {
        return false;
    }

    $db->exec('BEGIN');

    $delete = $db->prepare('DELETE FROM subscription_price_history WHERE subscription_id = :sid');
    if ($delete === false) {
        $db->exec('ROLLBACK');
        return false;
    }
    $delete->bindValue(':sid', $subscriptionId, SQLITE3_INTEGER);
    if (!$delete->execute()) {
        $db->exec('ROLLBACK');
        return false;
    }

    $insert = $db->prepare(
        'INSERT INTO subscription_price_history (subscription_id, period, price, note)
         VALUES (:sid, :period, :price, :note)'
    );
    if ($insert === false) {
        $db->exec('ROLLBACK');
        return false;
    }

    foreach ($entries as $entry) {
        $insert->bindValue(':sid', $subscriptionId, SQLITE3_INTEGER);
        $insert->bindValue(':period', $entry['period'], SQLITE3_TEXT);
        $insert->bindValue(':price', $entry['price'], SQLITE3_FLOAT);
        $insert->bindValue(':note', $entry['note'], SQLITE3_TEXT);
        if (!$insert->execute()) {
            $db->exec('ROLLBACK');
            return false;
        }
        $insert->reset();
        $insert->clear();
    }

    $db->exec('COMMIT');

    return true;
}
