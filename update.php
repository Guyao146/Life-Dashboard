<?php
declare(strict_types=1);

/*
 * Life Hub server-side updater.
 *
 * Update authorization reuses the Authentik administrator session. The
 * browser sends its access token in the same-origin POST body; this endpoint
 * verifies it against the configured UserInfo endpoint and administrator
 * allow lists before deploying the fixed remote branch.
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

function requestAuthToken(): string
{
    static $requestBody = null;
    if ($requestBody === null) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $requestBody = is_array($decoded) ? $decoded : [];
    }
    $token = $requestBody['accessToken'] ?? '';
    if (is_string($token) && trim($token) !== '') {
        return trim($token);
    }

    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim($header), $match) === 1 ? trim($match[1]) : '';
}

function fetchUserInfo(string $url, string $token): array
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('LIFE_HUB_OIDC_USERINFO_URL 必须是 HTTPS 地址');
    }
    if ($token === '' || strlen($token) > 8192) {
        throw new RuntimeException('缺少有效的 Authentik 管理员登录令牌');
    }

    $status = 0;
    $body = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json']]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
    } else {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true, 'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $match) === 1) $status = (int) $match[1];
    }
    if ($status === 401 || $status === 403) throw new RuntimeException('Authentik 管理员登录令牌无效或已过期');
    if ($status < 200 || $status >= 300 || !is_string($body)) throw new RuntimeException("Authentik UserInfo 校验失败（HTTP {$status}）");
    $claims = json_decode($body, true);
    if (!is_array($claims) || empty($claims['sub'])) throw new RuntimeException('Authentik UserInfo 响应无效');
    return $claims;
}

function allowList(string $key): array
{
    $raw = updateEnv($key);
    return array_values(array_filter(array_map(static fn(string $item): string => strtolower(trim($item)), preg_split('/[,;\r\n]+/', $raw) ?: [])));
}

function isAdministrator(array $claims): bool
{
    $allowedGroups = allowList('LIFE_HUB_ADMIN_GROUPS');
    $allowedUsers = allowList('LIFE_HUB_ADMIN_USERS');
    $allowedEmails = allowList('LIFE_HUB_ADMIN_EMAILS');
    if ($allowedGroups === [] && $allowedUsers === [] && $allowedEmails === []) return false;
    $groups = normalizedClaimGroups($claims);
    $username = strtolower(trim((string) ($claims['preferred_username'] ?? '')));
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    return array_intersect($allowedGroups, $groups) !== [] || ($username !== '' && in_array($username, $allowedUsers, true)) || ($email !== '' && in_array($email, $allowedEmails, true));
}

function normalizedClaimGroups(array $claims): array
{
    $raw = $claims['groups'] ?? $claims['ak_groups'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : (preg_split('/[,;\r\n]+/', $raw) ?: []);
    }
    if (!is_array($raw)) return [];
    $groups = array_map(static function (mixed $group): string {
        if (is_array($group)) $group = $group['name'] ?? $group['group_name'] ?? '';
        return strtolower(trim((string) $group));
    }, $raw);
    return array_values(array_unique(array_filter($groups)));
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

function deployCheckout(string $source, string $target, string $relative = '', ?callable $onFile = null): int
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
            $count += deployCheckout($sourcePath, $targetPath, $nextRelative, $onFile);
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
        if ($onFile !== null) {
            $onFile($nextRelative, $count);
        }
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

function deploymentFileCount(string $source, string $relative = ''): int
{
    $count = 0;
    foreach (scandir($source) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.git' || ($relative === '' && $name === '.env')) continue;
        $path = $source . DIRECTORY_SEPARATOR . $name;
        if (is_link($path)) throw new RuntimeException("拒绝部署符号链接：" . ($relative === '' ? $name : $relative . '/' . $name));
        $count += is_dir($path) ? deploymentFileCount($path, $relative === '' ? $name : $relative . '/' . $name) : 1;
    }
    return $count;
}

function beginUpdateStream(): void
{
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    while (ob_get_level() > 0) @ob_end_flush();
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
}

function sendUpdateEvent(string $state, string $stage, string $message, array $progress = [], array $extra = []): void
{
    $payload = array_merge([
        'ok' => $state !== 'error',
        'state' => $state,
        'stage' => $stage,
        'message' => $message,
        'progress' => $progress,
        'at' => gmdate('c'),
    ], $extra);
    echo "event: progress\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    flush();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => '升级接口只接受 POST 请求']);
}

if (!requestIsSameOrigin()) {
    respond(403, ['ok' => false, 'error' => '跨站请求被拒绝']);
}

$command = requestCommand();
if (!in_array($command, ['check', 'update', 'update-stream'], true)) {
    respond(400, ['ok' => false, 'error' => '指令必须为 check、update 或 update-stream']);
}

if ($command === 'update' || $command === 'update-stream') {
    $streamRequest = $command === 'update-stream';
    try {
        $claims = fetchUserInfo(updateEnv('LIFE_HUB_OIDC_USERINFO_URL'), requestAuthToken());
    } catch (Throwable $error) {
        if ($streamRequest) { beginUpdateStream(); sendUpdateEvent('error', 'error', $error->getMessage()); exit; }
        respond(401, ['ok' => false, 'error' => $error->getMessage()]);
    }
    if (!isAdministrator($claims)) {
        if ($streamRequest) { beginUpdateStream(); sendUpdateEvent('error', 'error', '当前 Authentik 账号不是看板管理员'); exit; }
        respond(403, ['ok' => false, 'error' => '当前 Authentik 账号不是看板管理员']);
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

if ($command === 'update-stream') {
    beginUpdateStream();
}

try {
    // 站点目录无需是 Git 工作区；远端代码只在 PHP 临时目录中维护。
    if ($command === 'update-stream') sendUpdateEvent('running', 'source', '正在准备安全的临时源码工作树…');
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
        if ($command === 'update-stream') {
            sendUpdateEvent('running', 'verify', '源码同步完成，正在校验远端发布版本…', [], ['commit' => $commit !== '' ? $commit : null]);
            $remoteVersion = remoteVersionFromCheckout($checkout);
            $totalFiles = deploymentFileCount($checkout);
            sendUpdateEvent('running', 'deploy', "远端 v{$remoteVersion} 已校验，开始原子部署 {$totalFiles} 个文件…", ['current' => 0, 'total' => $totalFiles], ['remoteVersion' => $remoteVersion]);
            $files = deployCheckout($checkout, $repository, '', static function (string $path, int $current) use ($totalFiles): void {
                sendUpdateEvent('running', 'deploy', "已部署 {$path}", ['current' => $current, 'total' => $totalFiles]);
            });
            sendUpdateEvent('running', 'cleanup', '正在清理旧浏览器配置和开发产物…', ['current' => $files, 'total' => $totalFiles]);
        } else {
            $files = deployCheckout($checkout, $repository);
        }
        removeLegacyPublicArtifacts($repository);
        @unlink($versionCache);
        $responsePayload = [
            'ok' => true,
            'message' => '升级完成',
            'commit' => $commit !== '' ? $commit : null,
            'files' => $files,
            'output' => "已部署 {$files} 个文件、清理旧浏览器配置与开发产物，并保留服务器 .env",
        ];
        if ($command === 'update-stream') {
            sendUpdateEvent('success', 'done', $responsePayload['output'], ['current' => $files, 'total' => $totalFiles], $responsePayload);
            $streamFinished = true;
        } else {
            $responseStatus = 200;
        }
    }
} catch (Throwable $error) {
    if ($command === 'update-stream') {
        sendUpdateEvent('error', 'error', $error->getMessage());
        $streamFinished = true;
    } else {
        $responseStatus = 500;
        $responsePayload = ['ok' => false, 'error' => $error->getMessage()];
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($command === 'update-stream') exit;
respond($responseStatus, $responsePayload);