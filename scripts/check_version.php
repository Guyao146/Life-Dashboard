<?php

declare(strict_types=1);

const VERSION_PATTERN = "/version:\\s*'([^']+)'/";
const RELEASE_DATE_PATTERN = "/releasedAt:\\s*'(\\d{4}-\\d{2}-\\d{2})'/";
const PRODUCT_FILES = ['index.html', 'app.js', 'styles.css', 'config.php', '.env.example'];

$root = dirname(__DIR__);
$versionFile = $root . DIRECTORY_SEPARATOR . 'version.js';
$configPath = $root . DIRECTORY_SEPARATOR . 'config.js';
$changelogFile = $root . DIRECTORY_SEPARATOR . 'CHANGELOG.md';

function fail(string $message): never
{
    fwrite(STDERR, "版本校验失败：{$message}" . PHP_EOL);
    exit(1);
}

function readFileOrFail(string $path): string
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        fail("无法读取 {$path}");
    }
    return $contents;
}

function git(array $arguments, bool $allowFailure = false): ?string
{
    global $root;
    $command = 'git -C ' . escapeshellarg($root);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        if ($allowFailure) {
            return null;
        }
        fail('Git 命令执行失败：' . implode(PHP_EOL, $output));
    }
    return implode("\n", $output);
}

function parseBase(array $arguments): ?string
{
    for ($index = 1, $count = count($arguments); $index < $count; $index++) {
        $argument = $arguments[$index];
        if ($argument === '--base') {
            return $arguments[++$index] ?? fail('--base 后必须提供 Git ref');
        }
        if (str_starts_with($argument, '--base=')) {
            return substr($argument, 7);
        }
        fail("不支持的参数 {$argument}");
    }
    return null;
}

function versionAt(string $ref): ?string
{
    $source = git(['show', "{$ref}:version.js"], true);
    if ($source === null || preg_match(VERSION_PATTERN, $source, $match) !== 1) {
        return null;
    }
    return $match[1];
}

$base = parseBase($argv);
$source = readFileOrFail($versionFile);
if (preg_match(VERSION_PATTERN, $source, $versionMatch) !== 1 || preg_match('/^\d+\.\d+\.\d+$/', $versionMatch[1]) !== 1) {
    fail('version.js 必须包含 X.Y.Z 格式的 version');
}
if (preg_match(RELEASE_DATE_PATTERN, $source) !== 1) {
    fail('version.js 必须包含 YYYY-MM-DD 格式的 releasedAt');
}
$version = $versionMatch[1];

$changelog = readFileOrFail($changelogFile);
$escapedVersion = preg_quote($version, '/');
if (preg_match("/^## \\[{$escapedVersion}\\] - \\d{4}-\\d{2}-\\d{2}$/m", $changelog) !== 1) {
    fail("CHANGELOG.md 缺少当前版本 {$version} 的日期标题");
}
if (preg_match("/^## \\[{$escapedVersion}\\].*?(?=^## \\[|\\z)/ms", $changelog, $section) === 1 && str_contains($section[0], 'TODO')) {
    fail("CHANGELOG.md 的 {$version} 版本仍包含 TODO");
}

if ($base !== null) {
    $changedOutput = git(['diff', '--name-only', "{$base}...HEAD"]) ?? '';
    $changedFiles = array_filter(array_map(
        static fn (string $path): string => str_replace('\\', '/', trim($path)),
        preg_split('/\R/', $changedOutput) ?: []
    ));
    $productChanged = count(array_intersect(PRODUCT_FILES, $changedFiles)) > 0;
    $oldVersion = versionAt($base);
    if ($productChanged && $oldVersion === $version) {
        fail("产品代码已更新，但版本号仍为 {$version}；请运行 php scripts/bump_version.php");
    }
    if ($productChanged && !in_array('CHANGELOG.md', $changedFiles, true)) {
        fail('产品代码已更新，但 CHANGELOG.md 未更新');
    }
}

echo "版本校验通过：v{$version}" . PHP_EOL;
