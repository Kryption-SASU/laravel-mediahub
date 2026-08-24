#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Fails the build when line coverage falls below a floor.
 *
 * Usage: php tools/coverage-gate.php <clover.xml> <minimum percentage>
 *
 * ⚠️ PHPUNIT HAS NO BUILT-IN THRESHOLD, which is why this file exists rather than a flag. The
 * alternative — reading the printed summary — breaks the first time the report format changes,
 * and breaks silently, in the direction of passing.
 *
 * ⚠️ A GLOBAL FLOOR IS A BLUNT INSTRUMENT, and it is worth knowing how it can be satisfied
 * dishonestly: on a large codebase, untested new code barely moves the total. The floor
 * catches a collapse, not a single careless addition. What catches that is the discipline in
 * CONTRIBUTING.md — tests in the same commit, seen failing first — and a reviewer who asks.
 *
 * ⚠️ AND IT COUNTS STATEMENTS, NOT BRANCHES. A line executed once counts as covered even when
 * only one side of its condition ever ran. Line coverage says what was NEVER exercised; it
 * does not say what was verified.
 */

$rapport = $argv[1] ?? '';
$plancher = (float) ($argv[2] ?? 0);

if ($rapport === '' || ! is_file($rapport)) {
    fwrite(STDERR, "coverage-gate: report not found: {$rapport}\n");

    exit(2);
}

$xml = @simplexml_load_file($rapport);

if ($xml === false) {
    fwrite(STDERR, "coverage-gate: report is not readable XML: {$rapport}\n");

    exit(2);
}

$metrics = $xml->project->metrics ?? null;

if ($metrics === null) {
    fwrite(STDERR, "coverage-gate: no project metrics in {$rapport}\n");

    exit(2);
}

$total = (int) $metrics['statements'];
$couvertes = (int) $metrics['coveredstatements'];

/*
 * ⚠️ AN EMPTY REPORT IS A FAILURE, NOT A PERFECT SCORE. A run that collected nothing — the
 * coverage driver missing, the source paths misconfigured — would otherwise divide by zero or
 * report 100%, and a gate that turns green when the measurement disappears is worse than none.
 */
if ($total === 0) {
    fwrite(STDERR, "coverage-gate: the report covers no statement at all — is the driver enabled?\n");

    exit(2);
}

$taux = round($couvertes / $total * 100, 2);

printf("Line coverage: %.2f%% (%d/%d statements), floor %.2f%%%s", $taux, $couvertes, $total, $plancher, PHP_EOL);

if ($taux + 0.001 < $plancher) {
    fwrite(STDERR, sprintf("coverage-gate: below the floor by %.2f points\n", $plancher - $taux));

    exit(1);
}

exit(0);
