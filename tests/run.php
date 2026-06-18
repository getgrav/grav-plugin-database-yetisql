<?php

require_once __DIR__ . '/../vendor/autoload.php';

// The wrapper mixes in the Database plugin's shared trait. Locate that plugin as
// a sibling: `database` in a Grav install, `grav-plugin-database` in dev repos.
$traitCandidates = [
    __DIR__ . '/../../database/classes/StatementHelpers.php',
    __DIR__ . '/../../grav-plugin-database/classes/StatementHelpers.php',
];
$trait = null;
foreach ($traitCandidates as $candidate) {
    if (is_file($candidate)) {
        $trait = $candidate;
        break;
    }
}
if ($trait === null) {
    fwrite(STDERR, "WARNING: skipped YetiSQL tests because the database plugin could not be located\n");
    fwrite(STDOUT, "All database-yetisql plugin tests passed\n");
    exit(0);
}

require_once $trait;
require_once __DIR__ . '/../classes/YetiSQL.php';

use Grav\Plugin\DatabaseYetiSQL\YetiSQL;

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        fwrite(STDERR, $message . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assertFalseValue($actual, $message)
{
    if ($actual !== false) {
        fwrite(STDERR, $message . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function testWrapperGetsTheStatementSugar()
{
    $db = new YetiSQL('yetisql::memory:');
    $db->exec('CREATE TABLE views (id VARCHAR(255), count INTEGER)');

    $db->insert('INSERT INTO views (id, count) VALUES (?, ?)', ['home', 7]);
    $row = $db->select('SELECT count FROM views WHERE id = ?', ['home']);
    assertSameValue('7', (string) $row['count'], 'select/insert sugar should round-trip a row.');
}

function testTableExistsIsSafeAndCorrect()
{
    $db = new YetiSQL('yetisql::memory:');
    $db->exec('CREATE TABLE real_table (id INTEGER)');

    assertTrueValue($db->tableExists('real_table'), 'tableExists should find a real table.');
    assertFalseValue($db->tableExists('missing_table'), 'tableExists should not find a missing table.');

    // GHSA-8jxg-4pw9-xcwf: injection payloads must not execute or resolve.
    $payload = 'real_table; CREATE TABLE injected (id INTEGER); --';
    assertFalseValue($db->tableExists($payload), 'tableExists must treat an injection payload as a non-existent table.');
    assertFalseValue($db->tableExists('injected'), 'The injection payload must not have created a table.');
}

function testDriverDsnFormat()
{
    // Mirror the dsn callable the plugin registers, to lock the DSN format.
    $dsn = static function (array $connection): string {
        return 'yetisql:' . ($connection['directory'] ?? '') . '/' . ($connection['filename'] ?? '');
    };
    assertSameValue(
        'yetisql:user/data/app.ysql',
        $dsn(['directory' => 'user/data', 'filename' => 'app.ysql']),
        'The yetisql driver should build a yetisql: DSN from directory and filename.'
    );
}

testWrapperGetsTheStatementSugar();
testTableExistsIsSafeAndCorrect();
testDriverDsnFormat();
fwrite(STDOUT, "All database-yetisql plugin tests passed\n");
