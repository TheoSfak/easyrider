<?php
/**
 * EasyRide - Dependency-free test runner
 *
 * Runs pure-function unit tests without PHPUnit, Composer, or a database.
 * Usage: php tests/run-tests.php   (exit code 0 = pass, 1 = failure)
 *
 * Keep tests here limited to functions with no DB/session/network side
 * effects. When Composer lands, migrate these to PHPUnit.
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only');
}

define('VOLUNTEEROPS', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';        // helpers only; no connection until db() is called
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$passed = 0;
$failed = 0;

function check(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "  ok  $name\n";
    } else {
        $failed++;
        echo "FAIL  $name\n      expected: " . var_export($expected, true)
           . "\n      actual:   " . var_export($actual, true) . "\n";
    }
}

function checkTrue(string $name, $condition): void {
    check($name, (bool) $condition, true);
}

// ---- h() ------------------------------------------------------------------
echo "h()\n";
check('escapes tags', h('<b>x</b>'), '&lt;b&gt;x&lt;/b&gt;');
check('escapes quotes', h("a\"b'c"), 'a&quot;b&#039;c');
check('null becomes empty string', h(null), '');
check('greek text untouched', h('Μέλη'), 'Μέλη');

// ---- sanitize() -----------------------------------------------------------
echo "sanitize()\n";
check('trims whitespace', sanitize('  x  '), 'x');
check('recurses arrays', sanitize([' a ', [' b ']]), ['a', ['b']]);
check('null becomes empty string', sanitize(null), '');
check('does not strip html', sanitize('<b>bold</b>'), '<b>bold</b>');

// ---- addMonthsToDate() ----------------------------------------------------
echo "addMonthsToDate()\n";
check('simple add', addMonthsToDate('2026-01-15', 1), '2026-02-15');
check('clamps to end of month', addMonthsToDate('2026-01-31', 1), '2026-02-28');
check('leap year clamp', addMonthsToDate('2024-01-31', 1), '2024-02-29');
check('year rollover', addMonthsToDate('2026-11-30', 3), '2027-02-28');
check('invalid date returns null', addMonthsToDate('2026-02-30', 1), null);
check('garbage returns null', addMonthsToDate('nope', 1), null);
check('empty returns null', addMonthsToDate('', 1), null);

// ---- paginate() -----------------------------------------------------------
echo "paginate()\n";
$p = paginate(101, 2, 20);
check('total pages', $p['total_pages'], 6.0);
check('offset for page 2', $p['offset'], 20);
checkTrue('has prev on page 2', $p['has_prev']);
checkTrue('has next on page 2', $p['has_next']);
$p = paginate(10, 99, 20);
check('page clamped to last', (int) $p['current_page'], 1);
$p = paginate(0, 1, 20);
check('zero items gives page 1', $p['current_page'], 1);
check('zero items gives offset 0', $p['offset'], 0);

// ---- validatePasswordStrength() --------------------------------------------
echo "validatePasswordStrength()\n";
checkTrue('too short rejected', validatePasswordStrength('Ab1') !== null);
checkTrue('missing uppercase rejected', validatePasswordStrength('abcdefg1') !== null);
checkTrue('missing lowercase rejected', validatePasswordStrength('ABCDEFG1') !== null);
checkTrue('missing digit rejected', validatePasswordStrength('Abcdefgh') !== null);
check('valid password accepted', validatePasswordStrength('Abcdefg1'), null);

// ---- validators -------------------------------------------------------------
echo "validators\n";
check('valid email accepted', validateEmail('a@b.gr'), null);
checkTrue('invalid email rejected', validateEmail('not-an-email') !== null);
check('valid date accepted', validateDate('2026-07-14'), null);
checkTrue('impossible date rejected', validateDate('2026-02-30') !== null);
checkTrue('wrong format rejected', validateDate('14/07/2026') !== null);
check('length in range accepted', validateLength('abc', 1, 5), null);
checkTrue('too long rejected', validateLength('abcdef', 1, 5) !== null);

// ---- dbEscape() (LIKE wildcards only) ---------------------------------------
echo "dbEscape()\n";
check('escapes percent', dbEscape('100%'), '100\\%');
check('escapes underscore', dbEscape('a_b'), 'a\\_b');
check('escapes backslash', dbEscape('a\\b'), 'a\\\\b');
check('plain text untouched', dbEscape('plain'), 'plain');

// ---- calculateHours() --------------------------------------------------------
echo "calculateHours()\n";
check('same-day span', calculateHours('2026-01-01 08:00', '2026-01-01 12:30'), 4.5);
check('multi-day span', calculateHours('2026-01-01 22:00', '2026-01-02 02:00'), 4.0);

// ---- buildGcalLink() ---------------------------------------------------------
echo "buildGcalLink()\n";
$link = buildGcalLink('Βόλτα', '2026-07-20 09:00', '2026-07-20 12:00', 'Ηράκλειο');
checkTrue('is a gcal url', strpos($link, 'https://calendar.google.com/calendar/render') === 0);
checkTrue('title url-encoded', strpos($link, rawurlencode('Βόλτα')) !== false);
checkTrue('contains dates', strpos($link, '20260720T090000/20260720T120000') !== false);

// ----------------------------------------------------------------------------
echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
