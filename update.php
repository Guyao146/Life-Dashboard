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

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function configuredToken(): string
{
    $token = getenv('LIFE_HUB_UPDATE_TOKEN');
    return is_string($token) ? trim($token) : '';
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
    $mode = strtolower(trim((string) (getenv('LIFE_HUB_UPDATE_MODE') ?: 'token')));
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
$branch = getenv('LIFE_HUB_UPDATE_BRANCH') ?: 'main';
if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
    respond(500, ['ok' => false, 'error' => '升级分支配置无效']);
}

$versionCache = versionCachePath($repository, $branch);
if ($command === 'check') {
    $cached = readVersionCache($versionCache);
    if ($cached !== null) {
        respond(200, ['ok' => true, 'cached' => true] + $cached);
    }
}

$lockPath = __DIR__ . DIRECTORY_SEPARATOR . '.life-hub-update.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    respond(409, ['ok' => false, 'error' => '已有升级任务正在执行，请稍后再试']);
}

try {
    // 固定 Git 子命令和分支，避免把浏览器输入拼进 shell 命令。
    $fetch = runGit($repository, 'git fetch --quiet origin ' . escapeshellarg($branch));
    if ($fetch['exitCode'] !== 0) {
        throw new RuntimeException('Git fetch 失败：' . ($fetch['output'] ?: '未知错误'));
    }

    if ($command === 'check') {
        $remoteFile = runGit($repository, 'git show ' . escapeshellarg('origin/' . $branch . ':version.js'));
        if ($remoteFile['exitCode'] !== 0) {
            throw new RuntimeException('无法读取远端 version.js：' . ($remoteFile['output'] ?: '未知错误'));
        }
        $remoteVersion = parseReleaseVersion($remoteFile['output']);
        $cachePayload = [
            'remoteVersion' => $remoteVersion,
            'branch' => $branch,
            'checkedAt' => gmdate('c'),
        ];
        @file_put_contents($versionCache, json_encode($cachePayload, JSON_UNESCAPED_SLASHES), LOCK_EX);
        $responseStatus = 200;
        $responsePayload = ['ok' => true, 'cached' => false] + $cachePayload;
    } else {
        $pull = runGit($repository, 'git pull --ff-only origin ' . escapeshellarg($branch));
        if ($pull['exitCode'] !== 0) {
            throw new RuntimeException('Git pull 失败：' . ($pull['output'] ?: '本地分支可能存在未推送提交或冲突'));
        }
        @unlink($versionCache);
        $head = runGit($repository, 'git rev-parse --short HEAD');
        $responseStatus = 200;
        $responsePayload = [
            'ok' => true,
            'message' => '升级完成',
            'commit' => $head['exitCode'] === 0 ? trim($head['output']) : null,
            'output' => function_exists('mb_substr') ? mb_substr($pull['output'], -2000) : substr($pull['output'], -2000),
        ];
    }
} catch (Throwable $error) {
    $responseStatus = 500;
    $responsePayload = ['ok' => false, 'error' => $error->getMessage()];
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($lockPath);
}

respond($responseStatus, $responsePayload);