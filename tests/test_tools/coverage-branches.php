<?php

/**
 * Lists the branches (and, with --paths, the paths) that a path-coverage run left unexecuted,
 * per function, from a `--coverage-php` report of the unit suite.
 *
 * `composer coverage-branches` runs the unit suite with path coverage and this script. The
 * arguments after `--` reach both commands: `--filter` and `--coverage-filter` narrow phpunit to
 * one test class and one source file, and this script reads the `--coverage-filter` value to
 * report that file only. Over the whole suite Xdebug's path coverage takes very long; over one
 * class and its test class it takes seconds and yields the same branch list CI reports.
 *
 *     composer coverage-branches -- --filter GAnalyticsModuleTest --coverage-filter src/GAnalyticsModule.php
 *
 * Xdebug enumerates every acyclic path through a function, so a loop or a chain of conditions
 * yields far more paths than tests can walk; the branch list is the actionable one, and 100% of
 * lines and branches is this project's coverage target.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$report = __DIR__ . '/../../build/coverage/paths.php';
$only = null;
$paths = false;
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
	switch ($args[$i]) {
		case '--coverage-filter':
			$only = basename((string) ($args[++$i] ?? ''));
			break;
		case '--paths':
			$paths = true;
			break;
		case '--filter':
			$i++;
			break;
		default:
			if (str_ends_with($args[$i], '.php') && is_file($args[$i])) {
				$report = $args[$i];
			}
	}
}
if (!is_file($report)) {
	fwrite(STDERR, "No coverage report at {$report}; run the unit suite with --path-coverage --coverage-php first.\n");
	exit(1);
}

/** @var \SebastianBergmann\CodeCoverage\CodeCoverage $coverage */
$coverage = require $report;
$root = realpath(__DIR__ . '/../../src') . DIRECTORY_SEPARATOR;
$totalBranches = $missedBranches = $totalPaths = $missedPaths = 0;
foreach ($coverage->getData(true)->functionCoverage() as $file => $functions) {
	if (!str_starts_with($file, $root) || ($only !== null && basename($file) !== $only)) {
		continue;
	}
	foreach ($functions as $name => $info) {
		foreach ($info['branches'] as $id => $branch) {
			$totalBranches++;
			if (empty($branch['hit'])) {
				$missedBranches++;
				echo basename($file), ' ', $name, " branch #{$id} lines {$branch['line_start']}-{$branch['line_end']}\n";
			}
		}
		foreach ($info['paths'] as $id => $path) {
			$totalPaths++;
			if (empty($path['hit'])) {
				$missedPaths++;
				if ($paths) {
					$lines = array_map(fn ($branch) => $info['branches'][$branch]['line_start'] ?? '?', $path['path']);
					echo basename($file), ' ', $name, " path #{$id} via lines ", implode('>', $lines), "\n";
				}
			}
		}
	}
}
echo "Branches not executed: {$missedBranches} of {$totalBranches}. Paths not executed: {$missedPaths} of {$totalPaths}", $paths ? '' : ' (list them with --paths)', ".\n";
exit($missedBranches === 0 ? 0 : 1);
