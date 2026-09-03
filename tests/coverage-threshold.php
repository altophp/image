<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

$path = $argv[1] ?? '';
$minimum = isset($argv[2]) ? (float) $argv[2] : 100.0;

if (!is_file($path)) {
    fwrite(STDERR, \sprintf("Coverage report not found: %s\n", $path));

    exit(1);
}

$report = simplexml_load_file($path);
$metrics = false === $report ? null : $report->project->metrics;
$total = null === $metrics ? 0 : (int) $metrics['statements'];
$covered = null === $metrics ? 0 : (int) $metrics['coveredstatements'];

if ($total < 1) {
    fwrite(STDERR, "Coverage report contains no line metrics.\n");

    exit(1);
}

$percentage = 100 * $covered / $total;

printf("Line coverage: %.2f%% (%d/%d), required: %.2f%%\n", $percentage, $covered, $total, $minimum);

if ($percentage < $minimum && false !== $report) {
    foreach ($report->xpath('//file') ?: [] as $file) {
        foreach ($file->line as $line) {
            if ('stmt' === (string) $line['type'] && 0 === (int) $line['count']) {
                printf("Uncovered: %s:%d\n", (string) $file['name'], (int) $line['num']);
            }
        }
    }
}

exit($percentage >= $minimum ? 0 : 1);
