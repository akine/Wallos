<?php

require_once WALLOS_ROOT . '/includes/variable_pricing.php';

wallos_test('variable pricing migration adds the flag, history table, and delete trigger', function () {
    $db = wallos_test_open_database();

    $column = $db->querySingle("SELECT COUNT(*) FROM pragma_table_info('subscriptions') WHERE name = 'has_variable_price'");
    assert_same(1, (int) $column, 'subscriptions.has_variable_price exists');

    $table = $db->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'subscription_price_history'");
    assert_same('subscription_price_history', $table, 'history table exists');

    $trigger = $db->querySingle("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = 'delete_subscription_price_history'");
    assert_same('delete_subscription_price_history', $trigger, 'deleting a subscription removes its history');

    $db->close();
});

wallos_test('fixed-price subscriptions ignore history and keep their base price', function () {
    $subscription = ['id' => 1, 'price' => 12.5, 'has_variable_price' => 0];
    $history = [1 => ['2026-10' => 40.0]];

    assert_equals(12.5, effective_subscription_price($subscription, '2026-10', $history),
        'a flag of 0 does not consult history');
    assert_true(!subscription_has_variable_price($subscription), '0 is not variable');
});

wallos_test('variable price uses the month, then the latest actual, then the base price', function () {
    $subscription = ['id' => 7, 'price' => 9.0, 'has_variable_price' => 1];
    $history = [7 => [
        '2026-08' => 30.0,
        '2026-09' => 42.5,
    ]];

    assert_equals(42.5, effective_subscription_price($subscription, '2026-09', $history),
        'the matching month wins');
    assert_equals(42.5, effective_subscription_price($subscription, '2026-10', $history),
        'a missing month falls back to the latest actual');
    assert_equals(9.0, effective_subscription_price(
        ['id' => 7, 'price' => 9.0, 'has_variable_price' => 1],
        '2026-10',
        []
    ), 'with no history the base price remains the fallback');
});

wallos_test('price history payload keeps valid months and drops the rest', function () {
    $entries = parse_price_history_json(json_encode([
        ['period' => '2026-13', 'price' => 10],
        ['period' => '2026-01', 'price' => '18.50', 'note' => '  winter  '],
        ['period' => 'nope', 'price' => 5],
        ['period' => '2026-01', 'price' => 19, 'note' => '<b>later</b>'],
        ['period' => '2026-02', 'price' => ''],
    ]));

    assert_same(1, count($entries), 'only one valid month survives, and a duplicate month keeps the later row');
    assert_same('2026-01', $entries[0]['period'], 'period is kept');
    assert_equals(19.0, $entries[0]['price'], 'the later amount replaces the earlier one');
    assert_same('later', $entries[0]['note'], 'tags are stripped and the note is trimmed');

    assert_same(null, parse_price_history_json('not-json'), 'invalid JSON does not wipe history');
    assert_same(null, parse_price_history_json(''), 'a missing payload does not wipe history');
    assert_same([], parse_price_history_json('[]'), 'an explicit empty list clears history');
});

wallos_test('saving history replaces that subscription only and deletion removes it', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $insert = $db->prepare('INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, has_variable_price)
                            VALUES (1, :name, 10, :currencyId, :nextPayment, 3, 1, 0, 1)');
    $insert->bindValue(':name', 'Electricity', SQLITE3_TEXT);
    $insert->bindValue(':currencyId', wallos_test_currency_id(1, 0), SQLITE3_INTEGER);
    $insert->bindValue(':nextPayment', '2026-10-20', SQLITE3_TEXT);
    $insert->execute();
    $electricityId = (int) $db->lastInsertRowID();

    $insert->bindValue(':name', 'Netflix', SQLITE3_TEXT);
    $insert->execute();
    $netflixId = (int) $db->lastInsertRowID();

    assert_true(replace_subscription_price_history($db, $electricityId, [
        ['period' => '2026-09', 'price' => 40.0, 'note' => 'hot'],
        ['period' => '2026-10', 'price' => 55.25, 'note' => ''],
    ]), 'history is stored');
    assert_true(replace_subscription_price_history($db, $netflixId, [
        ['period' => '2026-10', 'price' => 15.99, 'note' => ''],
    ]), 'another subscription can have its own month');

    assert_true(replace_subscription_price_history($db, $electricityId, [
        ['period' => '2026-10', 'price' => 61.0, 'note' => 'revised'],
    ]), 'a later save replaces the previous months');

    $index = load_price_history_index($db, 1);
    assert_equals(61.0, $index[$electricityId]['2026-10'], 'the revised amount is what totals will read');
    assert_true(!isset($index[$electricityId]['2026-09']), 'the month omitted from the save is gone');
    assert_equals(15.99, $index[$netflixId]['2026-10'], 'the other subscription is left alone');

    $loaded = price_history_for_subscription($db, $electricityId);
    assert_same('revised', $loaded[0]['note'], 'the note round-trips');

    $subscription = [
        'id' => $electricityId,
        'price' => 10,
        'has_variable_price' => 1,
        'next_payment' => '2026-10-20',
    ];
    assert_same('2026-10', subscription_price_month($subscription), 'the payment month is taken from next_payment');
    assert_equals(61.0, effective_subscription_price($subscription, subscription_price_month($subscription), $index),
        'the dashboard amount is the stored actual');

    $db->exec('DELETE FROM subscriptions WHERE id = ' . $electricityId);
    $remaining = (int) $db->querySingle('SELECT COUNT(*) FROM subscription_price_history WHERE subscription_id = ' . $electricityId);
    assert_same(0, $remaining, 'the delete trigger removes history with the subscription');
    $netflixRemaining = (int) $db->querySingle('SELECT COUNT(*) FROM subscription_price_history WHERE subscription_id = ' . $netflixId);
    assert_same(1, $netflixRemaining, 'another subscription\'s history stays');

    $db->close();
});
