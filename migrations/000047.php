<?php
// Adds variable pricing support: a per-subscription flag and a monthly price history table.

$columnExists = false;
$result = $db->query("PRAGMA table_info(subscriptions)");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($row['name'] === 'has_variable_price') {
        $columnExists = true;
        break;
    }
}

if (!$columnExists) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN has_variable_price INTEGER NOT NULL DEFAULT 0");
}

$db->exec("CREATE TABLE IF NOT EXISTS subscription_price_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscription_id INTEGER NOT NULL,
    period TEXT NOT NULL,
    price REAL NOT NULL,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
    UNIQUE(subscription_id, period)
)");

$db->exec("CREATE INDEX IF NOT EXISTS idx_price_history_sub_period
    ON subscription_price_history(subscription_id, period)");
