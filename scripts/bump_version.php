<?php

declare(strict_types=1);

const VERSION_PATTERN = "/version:\\s*'(\\d+\\.\\d+\\.\\d+)'/";
const RELEASE_DATE_PATTERN = "/releasedAt:\\s*'(\\d{4}-\\d{2}-\\d{2})'/";

$root = dirname(__DIR__);
$versionFile = $root . DIRECTORY_SEPARATOR . 'version.js';
$changelogFile = $root . DIRECTORY_SEPARATOR . 'CHANGELOG.md';

function fail(string $message): never
{
    fwrite(STDERR, "版本升级失败：{$message}" . PHP_EOL);
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

function writeFileOrFail(string $path, string $contents): void
{
    if (@file_put_contents($path, $contents) === false) {
        fail("无法写入 {$path}");
    }
}

function parseArguments(array $arguments): array
{
    $target = 'patch';
    $explicit = null;
    for ($index = 1, $count = count($arguments); $index < $count; $index++) {
        $argument = $arguments[$index];
        if ($argument === '--set') {
            $explicit = $arguments[++$index] ?? fail('--set 后必须提供 X.Y.Z 版本号');
            continue;
        }
        if (str_starts_with($argument, '--set=')) {
            $explicit = substr($argument, 6);
            continue;
        }
        if (in_array($argument, ['patch', 'minor', 'major'], true)) {
            $target = $argument;
            continue;
        }
        fail("不支持的参数 {$argument}");
    }
    return [$target, $explicit];
}

function nextVersion(string $current, string $requested): string
{
    if (preg_match('/^\d+\.\d+\.\d+$/', $requested) === 1) {
        return $requested;
    }
    [$major, $minor, $patch] = array_map('intval', explode('.', $current));
    return match ($requested) {
        'major' => ($major + 1) . '.0.0',
        'minor' => $major . '.' . ($minor + 1) . '.0',
        default => $major . '.' . $minor . '.' . ($patch + 1),
    };
}

[$targetType, $explicitVersion] = parseArguments($argv);
$versionSource = readFileOrFail($versionFile);
if (preg_match(VERSION_PATTERN, $versionSource, $versionMatch) !== 1) {
    fail('version.js 中没有有效的 SemVer 版本号');
}

$currentVersion = $versionMatch[1];
$targetVersion = nextVersion($currentVersion, $explicitVersion ?? $targetType);
if (version_compare($targetVersion, $currentVersion, '<=')) {
    fail("新版本 {$targetVersion} 必须高于当前版本 {$currentVersion}");
}

$today = date('Y-m-d');
$updatedSource = preg_replace(VERSION_PATTERN, "version: '{$targetVersion}'", $versionSource, 1);
$updatedSource = preg_replace(RELEASE_DATE_PATTERN, "releasedAt: '{$today}'", $updatedSource ?? '', 1);
if ($updatedSource === null || preg_match(RELEASE_DATE_PATTERN, $updatedSource) !== 1) {
    fail('version.js 中没有有效的 releasedAt 日期');
}
writeFileOrFail($versionFile, $updatedSource);

$changelog = readFileOrFail($changelogFile);
$insertion = "## [{$targetVersion}] - {$today}\n\n### 变更\n\n- TODO：在提交前填写本次更新内容。\n\n";
if (preg_match('/^## \[/m', $changelog, $headingMatch, PREG_OFFSET_CAPTURE) === 1) {
    $offset = $headingMatch[0][1];
    $changelog = substr($changelog, 0, $offset) . $insertion . substr($changelog, $offset);
} else {
    $changelog = rtrim($changelog) . "\n\n{$insertion}";
}
$changelog .= "\n[{$targetVersion}]: https://github.com/Guyao146/homeassistant-web/releases/tag/v{$targetVersion}\n";
writeFileOrFail($changelogFile, $changelog);

echo "版本已从 {$currentVersion} 升级到 {$targetVersion}" . PHP_EOL;
echo '下一步：填写 CHANGELOG.md 中的 TODO，再提交代码。' . PHP_EOL;
