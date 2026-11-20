<?php

declare(strict_types=1);

/*
 * Turns the failed tests of a PHPUnit JUnit report into GitHub Actions annotations
 * ("::error title=...::message"), so that the summary of a CI run names each failure and its message.
 *
 *   php .github/report-test-failures.php build/junit.xml
 */

$report = $argv[1] ?? 'build/junit.xml';
if (!is_file($report)) {
    echo "::error title=PHPUnit::No JUnit report at $report (PHPUnit did not finish)\n";
    exit(0);
}
$xml = simplexml_load_file($report);
if ($xml === false) {
    echo "::error title=PHPUnit::The JUnit report $report cannot be read\n";
    exit(0);
}

// GitHub's escaping: in the message, %, CR and LF; in a property such as the title, also ":" and ",".
$message = static fn (string $text): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $text);
$property = static fn (string $text): string => str_replace([':', ','], ['%3A', '%2C'], $message($text));
foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
    $problem = $case->failure ?? $case->error;
    $title = $case['class'] . '::' . $case['name'];
    // The message and the first lines of the trace are enough to tell the cause.
    $details = implode("\n", array_slice(explode("\n", trim((string) $problem)), 0, 15));
    echo '::error title=' . $property($title) . '::' . $message($details) . "\n";
}
