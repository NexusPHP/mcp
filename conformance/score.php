<?php

declare(strict_types=1);

/**
 * This file is part of the Nexus MCP SDK package.
 *
 * (c) 2026 John Paul E. Balandan, CPA <paulbalandan@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

/**
 * Aggregates the referee's per-scenario `checks.json` files into a score.
 *
 * The referee writes one directory per scenario under `conformance/results/`,
 * each holding a `checks.json` array of check objects. This walks them and
 * reports how many scenarios and checks passed.
 *
 * Skipped checks are counted but kept out of the denominator: the referee skips
 * a check when the surface it needs is absent, so counting them as failures
 * would punish a scenario for probing a capability the SDK never advertised.
 *
 * A WARNING is an unmet SHOULD. The referee's exit code treats one exactly like
 * a FAILURE, so this does too: a scenario carrying a warning has not passed.
 *
 *     php conformance/score.php            # markdown table
 *     php conformance/score.php --json     # machine-readable
 *     php conformance/score.php --badge    # rewrite conformance/badges/<mode>.json
 *
 * The badge files are committed, and CI regenerates them and fails when they differ
 * from the run, so the README badges cannot drift away from the measured score.
 */

require __DIR__.'/bootstrap.php';

$resultsDir = __DIR__.'/results';
$arguments = conformanceArguments();
$asJson = in_array('--json', $arguments, true);
$writeBadges = in_array('--badge', $arguments, true);

if (! is_dir($resultsDir)) {
    fwrite(\STDERR, "No conformance/results/ directory. Run ./conformance/run-server.sh first.\n");

    exit(1);
}

/** @var Closure(string): list<string> $findCheckFiles */
$findCheckFiles = static function (string $dir): array {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
    );

    $files = [];

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getBasename() === 'checks.json') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
};

/**
 * The referee's directory name is `<scenario>-<timestamp>` (server runs prefix
 * it with `server-`), so the trailing timestamp is stripped to recover the name.
 */
$scenarioName = static function (string $checkFile): string {
    $dir = basename(dirname($checkFile));

    return preg_replace('/-\d{4}-\d{2}-\d{2}T[\d.-]+Z?$/', '', $dir) ?? $dir;
};

/**
 * Each runner writes under `results/<mode>/`, so the two modes can be scored
 * together without one overwriting the other.
 */
$modeOf = static function (string $checkFile) use ($resultsDir): string {
    $relative = trim(str_replace($resultsDir, '', $checkFile), '/');
    $mode = strtok($relative, '/');

    return in_array($mode, ['server', 'client'], true) ? $mode : 'unknown';
};

$scenarios = [];
$totals = ['SUCCESS' => 0, 'FAILURE' => 0, 'WARNING' => 0, 'SKIPPED' => 0, 'INFO' => 0];

foreach ($findCheckFiles($resultsDir) as $checkFile) {
    $decoded = json_decode((string) file_get_contents($checkFile), true, 512, \JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        continue;
    }

    $name = $scenarioName($checkFile);
    $counts = ['SUCCESS' => 0, 'FAILURE' => 0, 'WARNING' => 0, 'SKIPPED' => 0, 'INFO' => 0];
    $failures = [];

    foreach ($decoded as $check) {
        if (! is_array($check) || ! is_string($check['status'] ?? null)) {
            continue;
        }

        $status = $check['status'];

        if (! array_key_exists($status, $counts)) {
            continue;
        }

        ++$counts[$status];
        ++$totals[$status];

        if ('FAILURE' === $status || 'WARNING' === $status) {
            $id = is_string($check['id'] ?? null) ? $check['id'] : '(unnamed check)';
            $failures[] = 'WARNING' === $status ? $id.' (SHOULD)' : $id;
        }
    }

    // A later run of the same scenario supersedes an earlier one, and the file
    // list is sorted, so the newest timestamp wins.
    $scenarios[$name] = ['mode' => $modeOf($checkFile), 'counts' => $counts, 'failures' => $failures];
}

if ([] === $scenarios) {
    fwrite(\STDERR, "conformance/results/ holds no checks.json files.\n");

    exit(1);
}

$scenariosPassed = 0;

foreach ($scenarios as $scenario) {
    if (0 === $scenario['counts']['FAILURE'] && 0 === $scenario['counts']['WARNING']) {
        ++$scenariosPassed;
    }
}

$scored = $totals['SUCCESS'] + $totals['FAILURE'] + $totals['WARNING'];
$rate = $scored > 0 ? $totals['SUCCESS'] / $scored : 0.0;

if ($writeBadges) {
    $perMode = [];

    foreach ($scenarios as $scenario) {
        $mode = $scenario['mode'];
        $perMode[$mode] ??= ['passed' => 0, 'scored' => 0];
        $perMode[$mode]['passed'] += $scenario['counts']['SUCCESS'];
        $perMode[$mode]['scored'] += $scenario['counts']['SUCCESS']
            + $scenario['counts']['FAILURE']
            + $scenario['counts']['WARNING'];
    }

    $badgeDir = __DIR__.'/badges';

    if (! is_dir($badgeDir) && ! mkdir($badgeDir, 0o755, true) && ! is_dir($badgeDir)) {
        fwrite(\STDERR, "Could not create conformance/badges/.\n");

        exit(1);
    }

    // Only the modes this run actually covered are rewritten. Each CI job runs one
    // mode, so touching the other would blank a badge that is still accurate.
    foreach ($perMode as $mode => $tally) {
        if ('unknown' === $mode || 0 === $tally['scored']) {
            continue;
        }

        $percent = (int) round($tally['passed'] / $tally['scored'] * 100);
        $badge = [
            'schemaVersion' => 1,
            'label' => sprintf('conformance (%s)', $mode),
            'message' => sprintf('%d/%d (%d%%)', $tally['passed'], $tally['scored'], $percent),
            'color' => match (true) {
                $percent >= 95 => 'brightgreen',
                $percent >= 85 => 'green',
                $percent >= 70 => 'yellow',
                default => 'orange',
            },
        ];

        file_put_contents(
            sprintf('%s/%s.json', $badgeDir, $mode),
            json_encode($badge, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n",
        );

        printf("Wrote conformance/badges/%s.json (%s).\n", $mode, $badge['message']);
    }

    exit(0);
}

if ($asJson) {
    echo json_encode([
        'scenarios' => ['passed' => $scenariosPassed, 'total' => count($scenarios)],
        'checks' => $totals,
        'passRate' => round($rate, 4),
        'detail' => $scenarios,
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";

    exit(0);
}

$modes = [];

foreach ($scenarios as $scenario) {
    $modes[$scenario['mode']] = true;
}

ksort($modes);
$heading = implode(' and ', array_keys($modes));

printf("## Conformance score (%s)\n\n", '' === $heading ? 'no mode' : $heading);
printf("**Scenarios passed:** %d / %d\n", $scenariosPassed, count($scenarios));
printf("**Checks passed:** %d / %d (%.1f%%)\n", $totals['SUCCESS'], $scored, $rate * 100);
printf("**Unmet SHOULD checks:** %d (counted against the score)\n", $totals['WARNING']);
printf("**Skipped checks:** %d (excluded from the denominator)\n\n", $totals['SKIPPED']);

echo "| Mode | Scenario | Pass | Fail | Warn | Skip | Not passing |\n";
echo "| --- | --- | ---: | ---: | ---: | ---: | --- |\n";

$rows = [];

foreach ($scenarios as $name => $scenario) {
    $rows[] = ['name' => $name] + $scenario;
}

usort($rows, static fn(array $a, array $b): int => [$a['mode'], $a['name']] <=> [$b['mode'], $b['name']]);

foreach ($rows as $scenario) {
    $name = $scenario['name'];
    printf(
        "| %s | `%s` | %d | %d | %d | %d | %s |\n",
        $scenario['mode'],
        $name,
        $scenario['counts']['SUCCESS'],
        $scenario['counts']['FAILURE'],
        $scenario['counts']['WARNING'],
        $scenario['counts']['SKIPPED'],
        [] === $scenario['failures'] ? '' : '`'.implode('`, `', $scenario['failures']).'`',
    );
}

// Reporting only. The gate is the referee's own exit code against
// expected-failures.yaml, which knows which failures are already accounted for.
exit(0);
