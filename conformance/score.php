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
 * Aggregates the referee's per-scenario `checks.json` files into a score, keeping skipped checks out of the
 * denominator and treating a WARNING (an unmet SHOULD) as a failure.
 *
 *     php conformance/score.php            # markdown table
 *     php conformance/score.php --json     # machine-readable
 *     php conformance/score.php --badge    # rewrite conformance/badges/<mode>.json
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

$scenarioName = static function (string $checkFile) use ($resultsDir): string {
    $segments = explode('/', trim(str_replace($resultsDir, '', dirname($checkFile)), '/'));

    array_shift($segments);
    $leaf = array_pop($segments) ?? '';
    $leaf = preg_replace('/-\d{4}-\d{2}-\d{2}T[\d.-]+Z?$/', '', $leaf) ?? $leaf;

    return implode('/', [...$segments, $leaf]);
};

$extensionPrefixes = ['tasks-'];

$extensionScenarios = [
    'auth/client-credentials-basic',
    'auth/client-credentials-jwt',
    'auth/dpop',
    'auth/dpop-nonce',
    'auth/enterprise-managed-authorization',
    'auth/wif-jwt-bearer',
];

$bucketOf = static function (string $name, string $mode) use ($extensionPrefixes, $extensionScenarios): string {
    if (in_array($name, $extensionScenarios, true)) {
        return 'extensions';
    }

    $bare = str_starts_with($name, $mode.'-') ? substr($name, strlen($mode) + 1) : $name;

    foreach ($extensionPrefixes as $prefix) {
        if (str_starts_with($bare, $prefix)) {
            return 'extensions';
        }
    }

    return 'spec';
};

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

        if ('FAILURE' === $status || 'WARNING' === $status) {
            $id = is_string($check['id'] ?? null) ? $check['id'] : '(unnamed check)';
            $failures[] = 'WARNING' === $status ? $id.' (SHOULD)' : $id;
        }
    }

    // The file list is sorted, so the newest timestamp wins and supersedes an earlier run of the same scenario.
    $mode = $modeOf($checkFile);
    $scenarios[$name] = ['mode' => $mode, 'bucket' => $bucketOf($name, $mode), 'counts' => $counts, 'failures' => $failures];
}

if ([] === $scenarios) {
    fwrite(\STDERR, "conformance/results/ holds no checks.json files.\n");

    exit(1);
}

$scenariosPassed = 0;

foreach ($scenarios as $scenario) {
    foreach ($scenario['counts'] as $status => $count) {
        $totals[$status] += $count;
    }

    if (0 === $scenario['counts']['FAILURE'] && 0 === $scenario['counts']['WARNING']) {
        ++$scenariosPassed;
    }
}

$scored = $totals['SUCCESS'] + $totals['FAILURE'] + $totals['WARNING'];
$rate = $scored > 0 ? $totals['SUCCESS'] / $scored : 0.0;

if ($writeBadges) {
    $perBadge = [];

    foreach ($scenarios as $scenario) {
        $mode = $scenario['mode'];
        $key = 'spec' === $scenario['bucket'] ? $mode : $mode.'-'.$scenario['bucket'];
        $perBadge[$key] ??= ['mode' => $mode, 'bucket' => $scenario['bucket'], 'passed' => 0, 'scored' => 0];
        $perBadge[$key]['passed'] += $scenario['counts']['SUCCESS'];
        $perBadge[$key]['scored'] += $scenario['counts']['SUCCESS']
            + $scenario['counts']['FAILURE']
            + $scenario['counts']['WARNING'];
    }

    $badgeDir = __DIR__.'/badges';

    if (! is_dir($badgeDir) && ! mkdir($badgeDir, 0o755, true) && ! is_dir($badgeDir)) {
        fwrite(\STDERR, "Could not create conformance/badges/.\n");

        exit(1);
    }

    foreach ($perBadge as $file => $tally) {
        if ('unknown' === $tally['mode'] || 0 === $tally['scored']) {
            continue;
        }

        $percent = (int) round($tally['passed'] / $tally['scored'] * 100);
        $badge = [
            'schemaVersion' => 1,
            'label' => 'spec' === $tally['bucket']
                ? sprintf('core (%s)', $tally['mode'])
                : sprintf('%s (%s)', $tally['bucket'], $tally['mode']),
            'message' => sprintf('%d/%d (%d%%)', $tally['passed'], $tally['scored'], $percent),
            'color' => match (true) {
                $percent >= 95 => 'brightgreen',
                $percent >= 85 => 'green',
                $percent >= 70 => 'yellow',
                default => 'orange',
            },
        ];

        file_put_contents(
            sprintf('%s/%s.json', $badgeDir, $file),
            json_encode($badge, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n",
        );

        printf("Wrote conformance/badges/%s.json (%s).\n", $file, $badge['message']);
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

printf("## Conformance score (%s)\n\n", $heading);
printf("**Scenarios passed:** %d / %d\n", $scenariosPassed, count($scenarios));
printf("**Checks passed:** %d / %d (%.1f%%)\n", $totals['SUCCESS'], $scored, $rate * 100);
printf("**Unmet SHOULD checks:** %d (counted against the score)\n", $totals['WARNING']);
printf("**Skipped checks:** %d (excluded from the denominator)\n", $totals['SKIPPED']);

$perBucket = [];

foreach ($scenarios as $scenario) {
    $bucket = $scenario['bucket'];
    $perBucket[$bucket] ??= ['passed' => 0, 'scored' => 0];
    $perBucket[$bucket]['passed'] += $scenario['counts']['SUCCESS'];
    $perBucket[$bucket]['scored'] += $scenario['counts']['SUCCESS']
        + $scenario['counts']['FAILURE']
        + $scenario['counts']['WARNING'];
}

if (count($perBucket) > 1) {
    ksort($perBucket);

    foreach ($perBucket as $bucket => $tally) {
        printf("**%s checks:** %d / %d\n", ucfirst($bucket), $tally['passed'], $tally['scored']);
    }
}

echo "\n";

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

// Reporting only: the gate is the referee's own exit code against expected-failures.yaml.
exit(0);
