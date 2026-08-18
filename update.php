<?php
declare(strict_types=1);

/*
 * Life Hub server-side updater.
 *
 * Required environment variable:
 *   LIFE_HUB_UPDATE_MODE=token   (recommended)
 *   LIFE_HUB_UPDATE_TOKEN=change-this-to-a-long-random-secret
 *
 * For the requested one-click mode, set LIFE_HUB_UPDATE_MODE=auto. This
 * removes the per-click secret, so anyone who can reach this endpoint can
 * request the fixed fast-forward update. Keep the endpoint private or put
 * it behind your reverse proxy/authentication when using auto mode.
 *
 * The browser never receives this value. The operator enters it once after
 * an update is detected and it is kept only in sessionStorage for that tab.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function updateEnv(string $key, string $default = ''): string
{
    static $fileValues = null;
    $runtime = getenv($key);
    if (is_string($runtime) && $runtime !== '') {
        return trim($runtime);
    }
    if ($fileValues === null) {
        $fileValues = [];
        $configuredPath = getenv('LIFE_HUB_ENV_FILE');
        $path = is_string($configuredPath) && trim($configuredPath) !== '' ? trim($configuredPath) : __DIR__ . DIRECTORY_SEPARATOR . '.env';
        foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$name,$value]=array_map('trim',explode('=',$line,2));
            if (preg_match('/^[A-Z][A-Z0-9_]*$/',$name)!==1) continue;
            if(strlen($value)>=2&&(($value[0]==='"'&&str_ends_with($value,'"'))||($value[0]==="'"&&str_ends_with($value,"'"))))$value=substr($value,1,-1);
            $fileValues[$name]=$value;
        }
    }
    return trim((string)($fileValues[$key]??$default));
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function configuredToken(): string
{
    return updateEnv('LIFE_HUB_UPDATE_TOKEN');
}

function requestToken(): string
{
    $header = $_SERVER['HTTP_X_LIFE_HUB_UPDATE_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        return trim($header);
    }

    // PHP-FPM/Apache may expose the header through this name instead.
    $fallback = $_SERVER['HTTP_X_UPDATE_TOKEN'] ?? '';
    return is_string($fallback) ? trim($fallback) : '';
}

function updateMode(): string
{
    $mode = strtolower(updateEnv('LIFE_HUB_UPDATE_MODE', 'token'));
    return in_array($mode, ['auto', 'token'], true) ? $mode : 'token';
}

function requestCommand(): string
{
    return strtolower(trim((string) ($_SERVER['HTTP_X_LIFE_HUB_UPDATE_COMMAND'] ?? '')));
}

function requestIsSameOrigin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return true;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = parse_url($origin, PHP_URL_HOST);
    // 反向代理终止 TLS 后，PHP 可能看到 HTTP；跨站请求仍需只按当前 Host 判断。
    return is_string($originHost) && $host !== '' && strcasecmp($originHost, preg_replace('/:\d+$/', '', $host)) === 0;
}

function runGit(string $repository, string $command): array
{
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $repository);
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动 Git 进程');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exitCode' => $exitCode,
        'output' => trim(($stdout ?: '') . "\n" . ($stderr ?: '')),
    ];
}

function versionCachePath(string $repository, string $branch): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'life-hub-version-' . hash('sha256', $repository . ':' . $branch) . '.json';
}

function updateLockPath(string $repository, string $branch): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'life-hub-update-' . hash('sha256', $repository . ':' . $branch) . '.lock';
}

function sourceCheckoutPath(string $repository, string $branch): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'life-hub-source-' . hash('sha256', $repository . ':' . $branch);
}

function repositoryUrl(): string
{
    $configured = updateEnv('LIFE_HUB_UPDATE_REPOSITORY');
    return $configured !== '' ? $configured : 'https://github.com/Guyao146/Life-Dashboard.git';
}

function removeDirectory(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            removeDirectory($path . DIRECTORY_SEPARATOR . $name);
        }
    }
    @rmdir($path);
}

function refreshSourceCheckout(string $checkout, string $remote, string $branch): string
{
    if (!is_dir($checkout . DIRECTORY_SEPARATOR . '.git')) {
        removeDirectory($checkout);
        $clone = runGit(
            sys_get_temp_dir(),
            'git clone --quiet --depth 1 --single-branch --branch '
                . escapeshellarg($branch) . ' ' . escapeshellarg($remote) . ' ' . escapeshellarg($checkout)
        );
        if ($clone['exitCode'] !== 0) {
            throw new RuntimeException('Git 浅克隆失败：' . ($clone['output'] ?: '未知错误'));
        }
    } else {
        $setRemote = runGit($checkout, 'git remote set-url origin ' . escapeshellarg($remote));
        if ($setRemote['exitCode'] !== 0) {
            throw new RuntimeException('更新远端地址失败：' . ($setRemote['output'] ?: '未知错误'));
        }
        $fetch = runGit($checkout, 'git fetch --quiet --depth 1 origin ' . escapeshellarg($branch));
        if ($fetch['exitCode'] !== 0) {
            throw new RuntimeException('Git fetch 失败：' . ($fetch['output'] ?: '未知错误'));
        }
        $reset = runGit($checkout, 'git reset --quiet --hard FETCH_HEAD');
        if ($reset['exitCode'] !== 0) {
            throw new RuntimeException('更新临时工作树失败：' . ($reset['output'] ?: '未知错误'));
        }
        runGit($checkout, 'git clean -quiet -fdx');
    }

    $head = runGit($checkout, 'git rev-parse --short HEAD');
    return $head['exitCode'] === 0 ? trim($head['output']) : '';
}

function remoteVersionFromCheckout(string $checkout): string
{
    $versionFile = $checkout . DIRECTORY_SEPARATOR . 'version.js';
    $source = @file_get_contents($versionFile);
    if ($source === false) {
        throw new RuntimeException('临时远端工作树中缺少 version.js');
    }
    return parseReleaseVersion($source);
}

function deployCheckout(string $source, string $target, string $relative = ''): int
{
    $count = 0;
    $preservedRootFiles = ['.env'];
    foreach (scandir($source) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.git') {
            continue;
        }
        if ($relative === '' && in_array($name, $preservedRootFiles, true)) {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $name;
        $targetPath = $target . DIRECTORY_SEPARATOR . $name;
        $nextRelative = $relative === '' ? $name : $relative . '/' . $name;
        if (is_link($sourcePath)) {
            throw new RuntimeException("拒绝部署符号链接：{$nextRelative}");
        }
        if (is_dir($sourcePath)) {
            if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                throw new RuntimeException("无法创建部署目录：{$nextRelative}");
            }
            $count += deployCheckout($sourcePath, $targetPath, $nextRelative);
            continue;
        }

        $temporary = $targetPath . '.life-hub-' . bin2hex(random_bytes(4)) . '.tmp';
        if (!@copy($sourcePath, $temporary)) {
            throw new RuntimeException("无法写入部署文件：{$nextRelative}");
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $targetPath)) {
            @unlink($temporary);
            throw new RuntimeException("无法替换部署文件：{$nextRelative}");
        }
        $count++;
    }
    return $count;
}

function removeLegacyBrowserConfigs(string $target): void
{
    foreach (['config.js', 'config.example.js'] as $name) {
        $path = $target . DIRECTORY_SEPARATOR . $name;
        if (file_exists($path) && !@unlink($path)) {
            throw new RuntimeException("无法删除遗留的浏览器配置文件：{$name}");
        }
    }
}

function removeLegacyPublicArtifacts(string $target): void
{
    removeLegacyBrowserConfigs($target);
    foreach (['work', 'outputs'] as $name) {
        $path = $target . DIRECTORY_SEPARATOR . $name;
        if (file_exists($path)) {
            removeDirectory($path);
            if (file_exists($path)) {
                throw new RuntimeException("无法删除遗留的公开开发目录：{$name}");
            }
        }
    }
}

function readVersionCache(string $path, int $maxAge = 300): ?array
{
    $modifiedAt = @filemtime($path);
    if ($modifiedAt === false || time() - $modifiedAt > $maxAge) {
        return null;
    }

    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['remoteVersion'])) {
        return null;
    }

    return $decoded;
}

function parseReleaseVersion(string $source): string
{
    if (preg_match('/version:\s*[\'\"](\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)[\'\"]/', $source, $match) !== 1) {
        throw new RuntimeException('远端 version.js 中没有有效的语义化版本号');
    }
    return $match[1];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => '升级接口只接受 POST 请求']);
}

if (!requestIsSameOrigin()) {
    respond(403, ['ok' => false, 'error' => '跨站请求被拒绝']);
}

$command = requestCommand();
if (!in_array($command, ['check', 'update'], true)) {
    respond(400, ['ok' => false, 'error' => '指令必须为 check 或 update']);
}

$mode = updateMode();
if ($command === 'update' && $mode === 'token') {
    $configured = configuredToken();
    if ($configured === '') {
        respond(503, ['ok' => false, 'requiresToken' => true, 'error' => '服务器未配置 LIFE_HUB_UPDATE_TOKEN']);
    }
    $provided = requestToken();
    if ($provided === '' || !hash_equals($configured, $provided)) {
        respond(401, ['ok' => false, 'requiresToken' => true, 'error' => '升级密钥无效']);
    }
}

$repository = __DIR__;
$branch = updateEnv('LIFE_HUB_UPDATE_BRANCH', 'main');
if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
    respond(500, ['ok' => false, 'error' => '升级分支配置无效']);
}
$remote = repositoryUrl();
$checkout = sourceCheckoutPath($repository, $branch);

$versionCache = versionCachePath($repository, $branch);
if ($command === 'check') {
    $cached = readVersionCache($versionCache);
    if ($cached !== null) {
        respond(200, ['ok' => true, 'cached' => true] + $cached);
    }
}

$lockPath = updateLockPath($repository, $branch);
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    respond(500, ['ok' => false, 'error' => 'PHP 临时目录不可写，无法创建升级锁']);
}

$hasLock = flock($lock, LOCK_EX | LOCK_NB);
if (!$hasLock && $command === 'check') {
    // 页面自动检查和手动检查可能同时发生；短暂等待正在执行的任务。
    for ($attempt = 0; $attempt < 10 && !$hasLock; $attempt++) {
        usleep(100000);
        $hasLock = flock($lock, LOCK_EX | LOCK_NB);
    }
    if (!$hasLock) {
        try {
            // 临时克隆正在刷新时读取上一份完整工作树，避免把检查误报为 409。
            $remoteVersion = remoteVersionFromCheckout($checkout);
            fclose($lock);
            respond(200, [
                'ok' => true,
                'cached' => false,
                'stale' => true,
                'busy' => true,
                'remoteVersion' => $remoteVersion,
                'branch' => $branch,
                'checkedAt' => gmdate('c'),
            ]);
        } catch (Throwable $error) {
            fclose($lock);
            respond(409, ['ok' => false, 'error' => '升级任务正在执行，请稍后再检查']);
        }
    }
}

if (!$hasLock) {
    fclose($lock);
    respond(409, ['ok' => false, 'error' => '已有升级任务正在执行，请稍后再试']);
}

try {
    // 站点目录无需是 Git 工作区；远端代码只在 PHP 临时目录中维护。
    $commit = refreshSourceCheckout($checkout, $remote, $branch);

    if ($command === 'check') {
        $remoteVersion = remoteVersionFromCheckout($checkout);
        $cachePayload = [
            'remoteVersion' => $remoteVersion,
            'branch' => $branch,
            'checkedAt' => gmdate('c'),
        ];
        @file_put_contents($versionCache, json_encode($cachePayload, JSON_UNESCAPED_SLASHES), LOCK_EX);
        $responseStatus = 200;
        $responsePayload = ['ok' => true, 'cached' => false] + $cachePayload;
    } else {
        $files = deployCheckout($checkout, $repository);
        removeLegacyPublicArtifacts($repository);
        @unlink($versionCache);
        $responseStatus = 200;
        $responsePayload = [
            'ok' => true,
            'message' => '升级完成',
            'commit' => $commit !== '' ? $commit : null,
            'files' => $files,
            'output' => "已部署 {$files} 个文件、清理旧浏览器配置与开发产物，并保留服务器 .env",
        ];
    }
} catch (Throwable $error) {
    $responseStatus = 500;
    $responsePayload = ['ok' => false, 'error' => $error->getMessage()];
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

respond($responseStatus, $responsePayload);