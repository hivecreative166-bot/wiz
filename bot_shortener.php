<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

const SESSION_AWAITING_DBI = 'awaiting_dbi_json';
const EVENT_LIMIT = 5000;

loadEnvFile(__DIR__ . '/.env');

$config = buildConfig();
$store = new JsonStore($config['db_path'], $config['base_short_url']);
$runtime = ['started_at' => time()];

if (PHP_SAPI === 'cli') {
    handleCli($config, $store, $runtime, $argv);
    exit;
}

handleWeb($config, $store, $runtime);
exit;


function buildConfig(): array
{
    $botToken = trim((string)envValue('BOT_TOKEN', ''));
    $ownerChatId = toIntOrNull(envValue('OWNER_CHAT_ID', ''));
    $salesAdminIds = parseIdList((string)envValue('SALES_ADMIN_IDS', ''));
    $contactTeam = trim((string)envValue('CONTACT_TEAM', ''));

    $baseShortUrl = trim((string)envValue('BASE_SHORT_URL', ''));
    if ($baseShortUrl === '') {
        if (PHP_SAPI !== 'cli') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseShortUrl = $https . '://' . $host;
        } else {
            $baseShortUrl = 'http://localhost:8080';
        }
    }
    $baseShortUrl = rtrim($baseShortUrl, '/');

    $dbPath = trim((string)envValue('DB_PATH', __DIR__ . '/db.json'));
    if ($dbPath === '') {
        $dbPath = __DIR__ . '/db.json';
    }

    if ($ownerChatId !== null && empty($salesAdminIds)) {
        $salesAdminIds = [$ownerChatId];
    }

    return [
        'bot_token' => $botToken,
        'owner_chat_id' => $ownerChatId,
        'sales_admin_ids' => $salesAdminIds,
        'contact_team' => $contactTeam,
        'base_short_url' => $baseShortUrl,
        'db_path' => $dbPath,
    ];
}

function handleCli(array $config, JsonStore $store, array $runtime, array $argv): void
{
    $mode = $argv[1] ?? 'help';

    if ($mode === 'poll') {
        if ($config['bot_token'] === '') {
            fwrite(STDERR, "BOT_TOKEN is required for polling mode.\n");
            exit(1);
        }
        if ($config['owner_chat_id'] === null) {
            fwrite(STDERR, "OWNER_CHAT_ID is required for polling mode.\n");
            exit(1);
        }

        echo "Starting Telegram long polling...\n";
        pollTelegram($config, $store, $runtime);
        return;
    }

    echo "Usage:\n";
    echo "  php bot_shortener.php poll\n\n";
    echo "Web mode (short URL redirect + webhook):\n";
    echo "  php -S 0.0.0.0:8080 bot_shortener.php\n";
}

function handleWeb(array $config, JsonStore $store, array $runtime): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';

    if ($path === '/health' || (isset($_GET['health']) && $_GET['health'] === '1')) {
        jsonResponse(['status' => 'ok', 'time_utc' => gmdate('c')], 200);
    }

    if ($path === '/telegram-webhook') {
        if ($config['bot_token'] === '' || $config['owner_chat_id'] === null) {
            jsonResponse(['ok' => false, 'error' => 'Missing BOT_TOKEN/OWNER_CHAT_ID'], 500);
        }
        $raw = file_get_contents('php://input');
        $update = json_decode((string)$raw, true);
        if (is_array($update)) {
            processUpdate($update, $config, $store, $runtime);
        }
        jsonResponse(['ok' => true], 200);
    }

    $code = extractCodeFromRequestPath($path);
    if ($code === null) {
        renderInfoPage();
    }

    $result = $store->resolveCode($code);
    if ($result['status'] === 'active') {
        header('Location: ' . $result['link']['original_url'], true, 302);
        exit;
    }

    if ($result['status'] === 'expired') {
        renderExpiredPage($code);
    }

    renderNotFoundPage($code);
}

function pollTelegram(array $config, JsonStore $store, array $runtime): void
{
    $offset = 0;

    while (true) {
        $res = tgApi($config['bot_token'], 'getUpdates', [
            'offset' => $offset,
            'timeout' => 30,
            'allowed_updates' => json_encode(['message']),
        ]);

        if (!($res['ok'] ?? false)) {
            usleep(700000);
            continue;
        }

        $updates = $res['result'] ?? [];
        if (!is_array($updates) || count($updates) === 0) {
            usleep(200000);
            continue;
        }

        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            if (isset($update['update_id']) && is_numeric($update['update_id'])) {
                $offset = (int)$update['update_id'] + 1;
            }
            processUpdate($update, $config, $store, $runtime);
        }
    }
}

function processUpdate(array $update, array $config, JsonStore $store, array $runtime): void
{
    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        return;
    }

    $from = $message['from'] ?? [];
    $chatId = (int)($from['id'] ?? 0);
    if ($chatId <= 0) {
        return;
    }

    $username = isset($from['username']) ? (string)$from['username'] : null;
    $firstName = isset($from['first_name']) ? (string)$from['first_name'] : null;
    $store->touchUser($chatId, $username, $firstName);

    $text = isset($message['text']) ? trim((string)$message['text']) : '';
    if ($text !== '' && strpos($text, '/') === 0) {
        [$command, $args] = parseCommand($text);
        if ($command === null) {
            return;
        }

        $store->recordEvent('command', $command, $chatId, []);
        dispatchCommand($command, $args, $message, $chatId, $config, $store, $runtime);
        return;
    }

    if (isOwner($chatId, $config)) {
        $session = $store->getSession($chatId);
        if (($session['pending'] ?? null) === SESSION_AWAITING_DBI) {
            $import = importDatabaseFromMessage($message, $config, $store);
            if ($import['ok']) {
                $store->setSession($chatId, null);
                $store->recordEvent('info', 'db_import_success', $chatId, []);
                sendMessage($config['bot_token'], $chatId, 'Database import successful.');
            } else {
                $store->recordEvent('error', 'db_import_failed', $chatId, ['error' => $import['error']]);
                sendMessage($config['bot_token'], $chatId, 'Import failed: ' . $import['error']);
            }
        }
    }
}

function dispatchCommand(string $command, array $args, array $message, int $chatId, array $config, JsonStore $store, array $runtime): void
{
    switch ($command) {
        case 'start':
            sendMessage($config['bot_token'], $chatId, "Welcome to URL Shortener Bot.\n\nUse /help to see all commands.\nYou need admin credits before using /st.");
            return;

        case 'help':
            sendMessage($config['bot_token'], $chatId, helpText());
            return;

        case 'me':
            $user = $store->getUser($chatId);
            $counts = $store->userLinkCounts($chatId);
            $access = (!empty($user['access_enabled'])) ? 'Yes' : 'No';
            $credits = (int)($user['credits'] ?? 0);
            $joined = (string)($user['joined_at'] ?? '-');
            $msg = "Profile\n" .
                "Chat ID: {$chatId}\n" .
                "Access: {$access}\n" .
                "Credits: {$credits}\n" .
                "Joined: {$joined}\n" .
                "Total Links: {$counts['total']}\n" .
                "Active: {$counts['active']}\n" .
                "Expired: {$counts['expired']}\n" .
                "Deleted: {$counts['deleted']}";
            sendMessage($config['bot_token'], $chatId, $msg);
            return;

        case 'buy':
            $supportTargets = supportTargetIds($config);
            $lines = [];
            foreach ($supportTargets as $id) {
                $lines[] = '- Admin ID: ' . $id . ' (tg://user?id=' . $id . ')';
            }
            if (!empty($config['contact_team'])) {
                $lines[] = '- Team: ' . $config['contact_team'];
            }
            if (count($lines) === 0) {
                $lines[] = '- Not configured. Ask owner to set OWNER_CHAT_ID / SALES_ADMIN_IDS.';
            }
            $contactList = implode("\n", $lines);
            sendMessage(
                $config['bot_token'],
                $chatId,
                "Premium Plan Request\nContact admin/team:\n{$contactList}\n\nYour request was also forwarded."
            );

            $name = (string)(($message['from']['first_name'] ?? '-') ?: '-');
            $username = (string)(($message['from']['username'] ?? '') ?: '');
            $usernameLine = ($username !== '') ? ('@' . $username) : '-';
            $requestText = "New /buy request\n" .
                "Name: {$name}\n" .
                "Username: {$usernameLine}\n" .
                "Chat ID: {$chatId}\n" .
                "Requested At (UTC): " . isoNow();

            $success = 0;
            $failed = 0;
            $failedIds = [];
            $failedReasons = [];
            foreach ($supportTargets as $adminId) {
                $send = sendMessageWithResult($config['bot_token'], $adminId, $requestText);
                if ($send['ok']) {
                    $success++;
                } else {
                    $failed++;
                    $failedIds[] = (string)$adminId;
                    $failedReasons[] = (string)$adminId . ': ' . (($send['error'] ?? '') ?: 'unknown error');
                }
            }

            $status = "Request forwarded to admins/team. Success: {$success}, Failed: {$failed}";
            if ($failed > 0) {
                $status .= "\nFailed targets: " . implode(', ', $failedIds);
                $status .= "\nReasons:\n" . implode("\n", $failedReasons);
            }
            sendMessage($config['bot_token'], $chatId, $status);
            $store->recordEvent('info', 'buy_forward', $chatId, [
                'success' => $success,
                'failed' => $failed,
                'targets' => $supportTargets,
                'failed_reasons' => $failedReasons,
            ]);
            return;

        case 'st':
            if ($store->isMaintenance() && !isOwner($chatId, $config)) {
                sendMessage($config['bot_token'], $chatId, 'Bot is under maintenance. Please try later.');
                return;
            }
            if (count($args) < 1) {
                sendMessage($config['bot_token'], $chatId, 'Usage: /st <url>');
                return;
            }
            $url = trim($args[0]);
            $create = $store->createShortLink($chatId, $url);
            if (!$create['ok']) {
                sendMessage($config['bot_token'], $chatId, $create['error']);
                return;
            }
            $link = $create['link'];
            $msg = "Short URL Created\n" .
                "Short: {$link['short_url']}\n" .
                "Original: {$link['original_url']}\n" .
                "Expires: {$link['expires_at']}";
            sendMessage($config['bot_token'], $chatId, $msg);
            return;

        case 'lst':
            $links = $store->listUserLinks($chatId, true);
            if (count($links) === 0) {
                sendMessage($config['bot_token'], $chatId, 'No active short URLs found.');
                return;
            }
            $out = ["Live Short URLs"];
            $max = min(30, count($links));
            for ($i = 0; $i < $max; $i++) {
                $l = $links[$i];
                $n = $i + 1;
                $out[] = "{$n}. {$l['short_url']} | clicks: {$l['click_count']} | expires: {$l['expires_at']}";
            }
            if (count($links) > 30) {
                $extra = count($links) - 30;
                $out[] = "...and {$extra} more";
            }
            sendMessage($config['bot_token'], $chatId, implode("\n", $out));
            return;

        case 'txt':
            $links = $store->listUserLinks($chatId, true);
            if (count($links) === 0) {
                sendMessage($config['bot_token'], $chatId, 'No active links to export.');
                return;
            }
            $rows = [
                'Generated at: ' . isoNow(),
                'Chat ID: ' . $chatId,
                '',
            ];
            foreach ($links as $l) {
                $rows[] = $l['short_url'] . ' -> ' . $l['original_url'];
            }
            $txt = implode("\n", $rows);
            sendDocumentFromText($config['bot_token'], $chatId, "short_links_{$chatId}.txt", $txt, 'Active short URLs mapping');
            return;

        case 'del':
            if (count($args) < 1) {
                sendMessage($config['bot_token'], $chatId, 'Usage: /del <short_url_or_code>');
                return;
            }
            $code = extractCodeFromInput($args[0], $config['base_short_url']);
            if ($code === null) {
                sendMessage($config['bot_token'], $chatId, 'Invalid short URL/code.');
                return;
            }
            $del = $store->deleteLink($chatId, (int)$config['owner_chat_id'], $code);
            if ($del['status'] === 'missing') {
                sendMessage($config['bot_token'], $chatId, 'Short URL not found.');
                return;
            }
            if ($del['status'] === 'forbidden') {
                sendMessage($config['bot_token'], $chatId, 'You can delete only your own short URLs.');
                return;
            }
            if ($del['status'] === 'already_deleted') {
                sendMessage($config['bot_token'], $chatId, 'This short URL is already deleted.');
                return;
            }
            sendMessage($config['bot_token'], $chatId, 'Deleted: ' . $del['link']['short_url']);
            return;

        case 'pro':
            if (!isOwner($chatId, $config)) {
                sendMessage($config['bot_token'], $chatId, 'Owner-only command.');
                return;
            }
            if (count($args) < 2) {
                sendMessage($config['bot_token'], $chatId, 'Usage: /pro [credit] [chat_id]');
                return;
            }
            $credit = toIntOrNull($args[0]);
            $target = toIntOrNull($args[1]);
            if ($credit === null || $credit <= 0 || $target === null || $target <= 0) {
                sendMessage($config['bot_token'], $chatId, 'Invalid parameters. Example: /pro 50 123456789');
                return;
            }
            $user = $store->setUserCredits($target, $credit, true);
            sendMessage($config['bot_token'], $chatId, "User {$target} promoted. Access enabled. Credits now: {$user['credits']}");
            return;

        case 'mt':
            if (!isOwner($chatId, $config)) {
                sendMessage($config['bot_token'], $chatId, 'Owner-only command.');
                return;
            }
            $state = $store->toggleMaintenance();
            sendMessage($config['bot_token'], $chatId, 'Maintenance mode: ' . ($state ? 'ON' : 'OFF'));
            return;

        case 'bc':
            if (!isOwner($chatId, $config)) {
                sendMessage($config['bot_token'], $chatId, 'Owner-only command.');
                return;
            }
            if (count($args) < 1) {
                sendMessage($config['bot_token'], $chatId, 'Usage: /bc [text]');
                return;
            }
            $msg = implode(' ', $args);
            $userIds = $store->allUserIds();
            if (count($userIds) === 0) {
                sendMessage($config['bot_token'], $chatId, 'No users found for broadcast.');
                return;
            }
            $sent = 0;
            $failed = 0;
            foreach ($userIds as $uid) {
                if (sendMessage($config['bot_token'], $uid, "Broadcast\n\n" . $msg)) {
                    $sent++;
                } else {
                    $failed++;
                }
                usleep(20000);
            }
            sendMessage($config['bot_token'], $chatId, "Broadcast complete. Sent: {$sent}, Failed: {$failed}");
            return;

        case 'stats':
            if (!isOwner($chatId, $config)) {
                sendMessage($config['bot_token'], $chatId, 'Owner-only command.');
                return;
            }
            $stats = $store->stats($runtime['started_at']);
            $active = $store->activeUserSummary();
            $maintenance = $store->isMaintenance();
            $text = "Server Analytics\n" .
                "Users: {$stats['users']}\n" .
                "Active Links: {$stats['active_links']}\n" .
                "Expired Links: {$stats['expired_links']}\n" .
                "Deleted Links: {$stats['deleted_links']}\n" .
                "Total Clicks: {$stats['total_clicks']}\n" .
                "Commands Today (UTC): {$stats['commands_today']}\n" .
                "Active Users 24h: {$active['active_24h']}\n" .
                "Active Users 7d: {$active['active_7d']}\n" .
                "Maintenance: " . ($maintenance ? 'ON' : 'OFF') . "\n" .
                "Uptime: " . formatUptime((int)$stats['uptime_seconds']);
            sendMessage($config['bot_token'], $chatId, $text);
            return;

        case 'dbi':
            if (!isOwner($chatId, $config)) {
                sendMessage($config['bot_token'], $chatId, 'Owner-only command.');
                return;
            }

            $replyTo = $message['reply_to_message'] ?? null;
            if (is_array($replyTo)) {
                $import = importDatabaseFromMessage($replyTo, $config, $store);
                if ($import['ok']) {
                    $store->setSession($chatId, null);
                    $store->recordEvent('info', 'db_import_success', $chatId, []);
                    sendMessage($config['bot_token'], $chatId, 'Database import successful.');
                } else {
                    $store->recordEvent('error', 'db_import_failed', $chatId, ['error' => $import['error']]);
                    sendMessage($config['bot_token'], $chatId, 'Import failed: ' . $import['error']);
                }
                return;
            }

            $store->setSession($chatId, SESSION_AWAITING_DBI);
            sendMessage($config['bot_token'], $chatId, "Reply with JSON file/message to import DB.\nPending session created for /dbi import.");
            return;

        case 'dbe':
            if (!isOwner($chatId, $config)) {
                sendMessage($config['bot_token'], $chatId, 'Owner-only command.');
                return;
            }
            $json = $store->exportJson();
            $name = 'db_export_' . date('Y-m-d_H-i-s') . '.json';
            sendDocumentFromText($config['bot_token'], $chatId, $name, $json, 'Database export');
            return;

        case 'cchk':
            if (!isOwner($chatId, $config)) {
                sendMessage($config['bot_token'], $chatId, 'Owner-only command.');
                return;
            }
            $sessions = $store->pendingSessions();
            $active = $store->activeUserSummary();
            $lines = [
                'Session Check',
                'Pending Sessions: ' . count($sessions),
                'Active Users 24h: ' . $active['active_24h'],
                'Active Users 7d: ' . $active['active_7d'],
            ];
            $max = min(20, count($sessions));
            for ($i = 0; $i < $max; $i++) {
                $s = $sessions[$i];
                $lines[] = '- chat_id=' . $s['chat_id'] . ' pending=' . $s['pending'] . ' updated_at=' . $s['updated_at'];
            }
            if (count($sessions) > 20) {
                $lines[] = '...and ' . (count($sessions) - 20) . ' more';
            }
            sendMessage($config['bot_token'], $chatId, implode("\n", $lines));
            return;

        default:
            sendMessage($config['bot_token'], $chatId, 'Unknown command. Use /help');
            return;
    }
}

function importDatabaseFromMessage(array $message, array $config, JsonStore $store): array
{
    $jsonText = null;

    if (isset($message['document']) && is_array($message['document'])) {
        $doc = $message['document'];
        $fileId = (string)($doc['file_id'] ?? '');
        if ($fileId === '') {
            return ['ok' => false, 'error' => 'Document file_id missing'];
        }
        $content = downloadTelegramDocument($config['bot_token'], $fileId);
        if (!$content['ok']) {
            return ['ok' => false, 'error' => $content['error']];
        }
        $jsonText = $content['data'];
    } elseif (isset($message['text']) && trim((string)$message['text']) !== '') {
        $jsonText = (string)$message['text'];
    } else {
        return ['ok' => false, 'error' => 'Reply must contain JSON text or JSON document'];
    }

    $decoded = json_decode((string)$jsonText, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON'];
    }

    $replace = $store->replaceAll($decoded);
    if (!$replace['ok']) {
        return ['ok' => false, 'error' => $replace['error']];
    }

    return ['ok' => true];
}

function downloadTelegramDocument(string $token, string $fileId): array
{
    $res = tgApi($token, 'getFile', ['file_id' => $fileId]);
    if (!($res['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'getFile failed'];
    }

    $path = $res['result']['file_path'] ?? '';
    if (!is_string($path) || $path === '') {
        return ['ok' => false, 'error' => 'file_path missing'];
    }

    $url = 'https://api.telegram.org/file/bot' . $token . '/' . $path;
    $data = @file_get_contents($url);
    if ($data === false) {
        return ['ok' => false, 'error' => 'Failed to download document'];
    }

    return ['ok' => true, 'data' => $data];
}

function parseCommand(string $text): array
{
    $parts = preg_split('/\s+/', trim($text));
    if (!$parts || count($parts) === 0) {
        return [null, []];
    }

    $cmdToken = $parts[0];
    if (strpos($cmdToken, '/') !== 0) {
        return [null, []];
    }

    $cmd = substr($cmdToken, 1);
    $atPos = strpos($cmd, '@');
    if ($atPos !== false) {
        $cmd = substr($cmd, 0, $atPos);
    }
    $cmd = strtolower(trim($cmd));

    $args = array_slice($parts, 1);
    return [$cmd, $args];
}

function helpText(): string
{
    return "USER COMMANDS\n\n" .
        "/me - profile\n" .
        "/buy - premium plans\n" .
        "/st <url> - shorten URL\n" .
        "/lst - show live short URLs\n" .
        "/txt - get active short->original map\n" .
        "/del <short_url_or_code> - delete URL\n\n" .
        "OWNER COMMANDS\n\n" .
        "/pro [credit] [chat_id] - promote user\n" .
        "/mt - toggle maintenance\n" .
        "/bc [text] - broadcast\n" .
        "/stats - analytics\n" .
        "/dbi - import DB (reply JSON)\n" .
        "/dbe - export JSON DB\n" .
        "/cchk - check sessions";
}

function isOwner(int $chatId, array $config): bool
{
    return $config['owner_chat_id'] !== null && $chatId === (int)$config['owner_chat_id'];
}

function supportTargetIds(array $config): array
{
    $targets = [];
    if ($config['owner_chat_id'] !== null) {
        $targets[] = (int)$config['owner_chat_id'];
    }
    if (!empty($config['sales_admin_ids']) && is_array($config['sales_admin_ids'])) {
        foreach ($config['sales_admin_ids'] as $id) {
            $id = toIntOrNull($id);
            if ($id !== null && $id > 0) {
                $targets[] = $id;
            }
        }
    }

    $targets = array_values(array_unique($targets));
    sort($targets);
    return $targets;
}

function sendMessage(string $token, $chatId, string $text): bool
{
    $res = sendMessageWithResult($token, $chatId, $text);
    return (bool)$res['ok'];
}

function sendMessageWithResult(string $token, $chatId, string $text): array
{
    $chatIdInt = toIntOrNull($chatId);
    if ($token === '' || $chatIdInt === null || $chatIdInt <= 0) {
        return ['ok' => false, 'error' => 'invalid token or chat_id'];
    }

    $res = tgApi($token, 'sendMessage', [
        'chat_id' => (string)$chatIdInt,
        'text' => $text,
    ]);

    if (!is_array($res) || !($res['ok'] ?? false)) {
        $desc = '';
        if (is_array($res)) {
            $desc = (string)($res['description'] ?? ($res['error'] ?? 'telegram send failed'));
        } else {
            $desc = 'telegram send failed';
        }
        return ['ok' => false, 'error' => $desc];
    }

    return ['ok' => true, 'error' => null];
}

function sendDocumentFromText(string $token, int $chatId, string $filename, string $content, string $caption = ''): bool
{
    if ($token === '' || $chatId <= 0) {
        return false;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'tgdoc_');
    if ($tmp === false) {
        return false;
    }
    file_put_contents($tmp, $content);

    $mime = str_ends_with($filename, '.json') ? 'application/json' : 'text/plain';

    if (function_exists('curl_init')) {
        $curlFile = new CURLFile($tmp, $mime, $filename);
        $res = tgApi($token, 'sendDocument', [
            'chat_id' => $chatId,
            'caption' => $caption,
            'document' => $curlFile,
        ], true);
    } else {
        $res = tgApiMultipartViaCurlCli($token, 'sendDocument', [
            'chat_id' => (string)$chatId,
            'caption' => $caption,
        ], [
            'field' => 'document',
            'path' => $tmp,
            'mime' => $mime,
            'filename' => $filename,
        ]);
    }

    @unlink($tmp);
    return (bool)($res['ok'] ?? false);
}

function tgApi(string $token, string $method, array $params = [], bool $multipart = false): array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => $error];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Invalid JSON response'];
    }

    if (commandExists('curl')) {
        if ($multipart) {
            return ['ok' => false, 'error' => 'multipart requires curl extension or tgApiMultipartViaCurlCli'];
        }
        $query = http_build_query($params);
        $cmd = 'curl -sS --connect-timeout 20 --max-time 40 -X POST --data-raw ' .
            escapeshellarg($query) . ' ' . escapeshellarg($url) . ' 2>/tmp/telegram_api_err.log';
        $response = shell_exec($cmd);
        if (!is_string($response) || trim($response) === '') {
            return ['ok' => false, 'error' => 'curl-cli request failed'];
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Invalid JSON response from curl-cli'];
        }
        return $decoded;
    }

    if ($multipart) {
        return ['ok' => false, 'error' => 'cURL required for multipart requests'];
    }

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($params),
            'timeout' => 35,
        ],
    ];
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['ok' => false, 'error' => 'HTTP request failed'];
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Invalid JSON response'];
}

function tgApiMultipartViaCurlCli(string $token, string $method, array $fields, array $file): array
{
    if (!commandExists('curl')) {
        return ['ok' => false, 'error' => 'curl-cli not available'];
    }
    if (!is_file((string)($file['path'] ?? ''))) {
        return ['ok' => false, 'error' => 'file not found'];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $parts = [
        'curl',
        '-sS',
        '--connect-timeout', '20',
        '--max-time', '60',
        '-X', 'POST',
    ];

    foreach ($fields as $k => $v) {
        $parts[] = '-F';
        $parts[] = escapeshellarg((string)$k . '=' . (string)$v);
    }

    $field = (string)$file['field'];
    $path = (string)$file['path'];
    $mime = (string)($file['mime'] ?? 'application/octet-stream');
    $filename = (string)($file['filename'] ?? basename($path));
    $fileExpr = $field . '=@' . $path . ';type=' . $mime . ';filename=' . $filename;
    $parts[] = '-F';
    $parts[] = escapeshellarg($fileExpr);
    $parts[] = escapeshellarg($url);
    $parts[] = '2>/tmp/telegram_api_err.log';

    $cmd = implode(' ', $parts);
    $response = shell_exec($cmd);
    if (!is_string($response) || trim($response) === '') {
        return ['ok' => false, 'error' => 'curl-cli multipart request failed'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON response from curl-cli multipart'];
    }
    return $decoded;
}

function extractCodeFromInput(string $value, string $baseShortUrl): ?string
{
    $candidate = trim($value);
    if ($candidate === '') {
        return null;
    }

    $base = rtrim($baseShortUrl, '/');
    if ($base !== '' && strpos($candidate, $base . '/') === 0) {
        $candidate = substr($candidate, strlen($base) + 1);
    } elseif (preg_match('#^https?://#i', $candidate) === 1) {
        $path = parse_url($candidate, PHP_URL_PATH);
        $candidate = is_string($path) ? ltrim($path, '/') : '';
    }

    $candidate = explode('/', $candidate)[0] ?? '';
    $candidate = explode('?', $candidate)[0] ?? '';
    $candidate = explode('#', $candidate)[0] ?? '';

    if (preg_match('/^[A-Za-z0-9_-]{3,64}$/', $candidate) !== 1) {
        return null;
    }
    return $candidate;
}

function extractCodeFromRequestPath(string $path): ?string
{
    if (isset($_GET['code']) && is_string($_GET['code'])) {
        $code = trim($_GET['code']);
        if (preg_match('/^[A-Za-z0-9_-]{3,64}$/', $code) === 1) {
            return $code;
        }
    }

    $trimmed = trim($path, '/');
    if ($trimmed === '') {
        return null;
    }

    $parts = explode('/', $trimmed);
    $last = end($parts);
    if (!is_string($last)) {
        return null;
    }

    if (preg_match('/^[A-Za-z0-9_-]{3,64}$/', $last) !== 1) {
        return null;
    }

    return $last;
}

function renderInfoPage(): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>PHP Bot + Shortener</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8f9fb;color:#1f2937}.card{max-width:660px;margin:48px auto;background:#fff;border-radius:10px;padding:24px;box-shadow:0 10px 20px rgba(0,0,0,.08)}h1{margin-top:0}</style>';
    echo '</head><body><div class="card">';
    echo '<h1>PHP Bot + URL Shortener</h1>';
    echo '<p>Use <code>/health</code> for health check, <code>/telegram-webhook</code> for webhook updates, and <code>/CODE</code> for short redirects.</p>';
    echo '</div></body></html>';
    exit;
}

function renderExpiredPage(string $code): void
{
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Link Expired</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8f9fb;color:#1f2937}.card{max-width:560px;margin:48px auto;background:#fff;border-radius:10px;padding:24px;box-shadow:0 10px 20px rgba(0,0,0,.08)}h1{margin-top:0}</style>';
    echo '</head><body><div class="card"><h1>Link Expired</h1><p>This short URL (<code>' . $safe . '</code>) expired after 30 days.</p></div></body></html>';
    exit;
}

function renderNotFoundPage(string $code): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Link Not Available</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8f9fb;color:#1f2937}.card{max-width:560px;margin:48px auto;background:#fff;border-radius:10px;padding:24px;box-shadow:0 10px 20px rgba(0,0,0,.08)}h1{margin-top:0}</style>';
    echo '</head><body><div class="card"><h1>Link Not Available</h1><p>The short URL (<code>' . $safe . '</code>) was deleted or does not exist.</p></div></body></html>';
    exit;
}

function jsonResponse(array $payload, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function isoNow(): string
{
    return gmdate('c');
}

function parseTs(string $value): ?int
{
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return (int)$ts;
}

function isValidHttpUrl(string $url): bool
{
    if ($url === '') {
        return false;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

function randomCode(int $length): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function toIntOrNull($value): ?int
{
    if ($value === null) {
        return null;
    }
    if (is_int($value)) {
        return $value;
    }
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        return null;
    }
    return (int)$value;
}

function parseIdList(string $raw): array
{
    $out = [];
    foreach (explode(',', $raw) as $piece) {
        $piece = trim($piece);
        if ($piece === '') {
            continue;
        }
        $id = toIntOrNull($piece);
        if ($id !== null) {
            $out[] = $id;
        }
    }
    return array_values(array_unique($out));
}

function envValue(string $name, $default = null)
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    return $value;
}

function commandExists(string $command): bool
{
    $cmd = 'command -v ' . escapeshellarg($command) . ' >/dev/null 2>&1; echo $?';
    $out = shell_exec($cmd);
    return trim((string)$out) === '0';
}

function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($key === '') {
            continue;
        }
        $existing = getenv($key);
        if ($existing !== false && $existing !== '') {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function formatUptime(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $rem = $seconds % 86400;
    $hours = intdiv($rem, 3600);
    $rem %= 3600;
    $minutes = intdiv($rem, 60);
    $secs = $rem % 60;
    return $days . 'd ' . $hours . 'h ' . $minutes . 'm ' . $secs . 's';
}


class JsonStore
{
    private string $dbPath;
    private string $lockPath;
    private string $baseShortUrl;

    public function __construct(string $dbPath, string $baseShortUrl)
    {
        $this->dbPath = $dbPath;
        $this->lockPath = $dbPath . '.lock';
        $this->baseShortUrl = rtrim($baseShortUrl, '/');

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_file($this->dbPath)) {
            $this->saveData($this->defaultData());
        } else {
            $loaded = $this->loadData();
            $merged = $this->normalizeData($loaded);
            $this->saveData($merged);
        }
    }

    public function touchUser(int $chatId, ?string $username, ?string $firstName): void
    {
        $this->withLock(function (array &$data) use ($chatId, $username, $firstName): void {
            $this->ensureUser($data, $chatId, $username, $firstName);
        });
    }

    private function withLock(callable $fn)
    {
        $lockHandle = fopen($this->lockPath, 'c+');
        if ($lockHandle === false) {
            throw new RuntimeException('Unable to open DB lock file');
        }

        flock($lockHandle, LOCK_EX);
        $data = $this->normalizeData($this->loadDataNoThrow());
        $result = $fn($data);
        $this->saveData($data);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        return $result;
    }

    public function getUser(int $chatId): array
    {
        $data = $this->normalizeData($this->loadData());
        $key = (string)$chatId;
        if (!isset($data['users'][$key])) {
            return [];
        }
        return $data['users'][$key];
    }

    public function setUserCredits(int $chatId, int $addCredits, bool $accessEnabled): array
    {
        return $this->withLock(function (array &$data) use ($chatId, $addCredits, $accessEnabled): array {
            $user = $this->ensureUser($data, $chatId, null, null);
            $user['credits'] = (int)$user['credits'] + $addCredits;
            if ($accessEnabled) {
                $user['access_enabled'] = true;
            }
            $user['last_active_at'] = isoNow();
            $data['users'][(string)$chatId] = $user;
            return $user;
        });
    }

    public function createShortLink(int $chatId, string $originalUrl): array
    {
        if (!isValidHttpUrl($originalUrl)) {
            return ['ok' => false, 'error' => 'Invalid URL. Please send a valid http/https URL.'];
        }

        return $this->withLock(function (array &$data) use ($chatId, $originalUrl): array {
            $this->expireLinks($data);
            $user = $this->ensureUser($data, $chatId, null, null);

            if (empty($user['access_enabled'])) {
                return ['ok' => false, 'error' => 'Access denied. Ask admin for credits using /buy.'];
            }
            if ((int)$user['credits'] < 1) {
                return ['ok' => false, 'error' => 'No credits left. Contact admin using /buy.'];
            }

            $codeLength = max(4, min(12, (int)$data['settings']['code_length']));
            $code = null;
            for ($i = 0; $i < 64; $i++) {
                $candidate = randomCode($codeLength);
                if (!isset($data['links'][$candidate])) {
                    $code = $candidate;
                    break;
                }
            }
            if ($code === null) {
                return ['ok' => false, 'error' => 'Failed to generate unique code'];
            }

            $now = time();
            $expiryDays = (int)$data['settings']['expiry_days'];
            $expiresAt = $now + ($expiryDays * 86400);

            $link = [
                'code' => $code,
                'owner_chat_id' => $chatId,
                'original_url' => trim($originalUrl),
                'short_url' => $this->baseShortUrl . '/' . $code,
                'created_at' => gmdate('c', $now),
                'expires_at' => gmdate('c', $expiresAt),
                'status' => 'active',
                'click_count' => 0,
                'last_clicked_at' => null,
                'last_used_at' => gmdate('c', $now),
            ];

            $data['links'][$code] = $link;
            $user['credits'] = (int)$user['credits'] - 1;
            $user['last_active_at'] = isoNow();
            $data['users'][(string)$chatId] = $user;

            return ['ok' => true, 'link' => $link];
        });
    }

    public function listUserLinks(int $chatId, bool $activeOnly): array
    {
        return $this->withLock(function (array &$data) use ($chatId, $activeOnly): array {
            $this->expireLinks($data);
            $rows = [];
            foreach ($data['links'] as $link) {
                if ((int)($link['owner_chat_id'] ?? 0) !== $chatId) {
                    continue;
                }
                if ($activeOnly && ($link['status'] ?? '') !== 'active') {
                    continue;
                }
                $rows[] = $link;
            }
            usort($rows, function (array $a, array $b): int {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            });
            return $rows;
        });
    }

    public function resolveCode(string $code): array
    {
        return $this->withLock(function (array &$data) use ($code): array {
            if (!isset($data['links'][$code]) || !is_array($data['links'][$code])) {
                return ['status' => 'missing'];
            }

            $link = $data['links'][$code];
            $status = strtolower((string)($link['status'] ?? 'active'));

            if ($status === 'deleted') {
                return ['status' => 'deleted', 'link' => $link];
            }

            $now = time();
            $expiresTs = parseTs((string)($link['expires_at'] ?? ''));
            if ($status === 'expired' || $expiresTs === null || $expiresTs <= $now) {
                $link['status'] = 'expired';
                $link['last_used_at'] = gmdate('c', $now);
                $data['links'][$code] = $link;
                return ['status' => 'expired', 'link' => $link];
            }

            $link['click_count'] = (int)($link['click_count'] ?? 0) + 1;
            $link['last_clicked_at'] = gmdate('c', $now);
            $link['last_used_at'] = gmdate('c', $now);
            $data['links'][$code] = $link;

            return ['status' => 'active', 'link' => $link];
        });
    }

    public function deleteLink(int $requesterChatId, int $ownerChatId, string $code): array
    {
        return $this->withLock(function (array &$data) use ($requesterChatId, $ownerChatId, $code): array {
            $this->expireLinks($data);
            if (!isset($data['links'][$code]) || !is_array($data['links'][$code])) {
                return ['status' => 'missing', 'link' => null];
            }
            $link = $data['links'][$code];

            if ($requesterChatId !== $ownerChatId && (int)($link['owner_chat_id'] ?? 0) !== $requesterChatId) {
                return ['status' => 'forbidden', 'link' => null];
            }
            if (($link['status'] ?? '') === 'deleted') {
                return ['status' => 'already_deleted', 'link' => $link];
            }

            $link['status'] = 'deleted';
            $link['last_used_at'] = isoNow();
            $data['links'][$code] = $link;
            return ['status' => 'deleted', 'link' => $link];
        });
    }

    public function userLinkCounts(int $chatId): array
    {
        return $this->withLock(function (array &$data) use ($chatId): array {
            $this->expireLinks($data);
            $counts = ['total' => 0, 'active' => 0, 'expired' => 0, 'deleted' => 0];
            foreach ($data['links'] as $link) {
                if ((int)($link['owner_chat_id'] ?? 0) !== $chatId) {
                    continue;
                }
                $counts['total']++;
                $status = (string)($link['status'] ?? 'active');
                if (!isset($counts[$status])) {
                    continue;
                }
                $counts[$status]++;
            }
            return $counts;
        });
    }

    public function allUserIds(): array
    {
        $data = $this->normalizeData($this->loadData());
        $ids = [];
        foreach ($data['users'] as $key => $user) {
            $ids[] = (int)$key;
        }
        sort($ids);
        return $ids;
    }

    public function isMaintenance(): bool
    {
        $data = $this->normalizeData($this->loadData());
        return (bool)($data['settings']['maintenance'] ?? false);
    }

    public function toggleMaintenance(): bool
    {
        return $this->withLock(function (array &$data): bool {
            $new = !((bool)($data['settings']['maintenance'] ?? false));
            $data['settings']['maintenance'] = $new;
            return $new;
        });
    }

    public function setSession(int $chatId, ?string $pending): void
    {
        $this->withLock(function (array &$data) use ($chatId, $pending): void {
            $key = (string)$chatId;
            if ($pending === null) {
                unset($data['sessions'][$key]);
                return;
            }
            $data['sessions'][$key] = [
                'chat_id' => $chatId,
                'pending' => $pending,
                'updated_at' => isoNow(),
            ];
        });
    }

    public function getSession(int $chatId): ?array
    {
        $data = $this->normalizeData($this->loadData());
        $key = (string)$chatId;
        if (!isset($data['sessions'][$key]) || !is_array($data['sessions'][$key])) {
            return null;
        }
        return $data['sessions'][$key];
    }

    public function pendingSessions(): array
    {
        $data = $this->normalizeData($this->loadData());
        $rows = [];
        foreach ($data['sessions'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (empty($row['pending'])) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, function (array $a, array $b): int {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });
        return $rows;
    }

    public function activeUserSummary(): array
    {
        $data = $this->normalizeData($this->loadData());
        $now = time();
        $cut24 = $now - 86400;
        $cut7d = $now - (7 * 86400);
        $a24 = 0;
        $a7d = 0;

        foreach ($data['users'] as $user) {
            if (!is_array($user)) {
                continue;
            }
            $last = parseTs((string)($user['last_active_at'] ?? ''));
            if ($last === null) {
                continue;
            }
            if ($last >= $cut24) {
                $a24++;
            }
            if ($last >= $cut7d) {
                $a7d++;
            }
        }

        return ['active_24h' => $a24, 'active_7d' => $a7d];
    }

    public function stats(int $startedAt): array
    {
        return $this->withLock(function (array &$data) use ($startedAt): array {
            $this->expireLinks($data);
            $active = 0;
            $expired = 0;
            $deleted = 0;
            $clicks = 0;
            foreach ($data['links'] as $link) {
                $status = (string)($link['status'] ?? 'active');
                if ($status === 'active') {
                    $active++;
                } elseif ($status === 'expired') {
                    $expired++;
                } elseif ($status === 'deleted') {
                    $deleted++;
                }
                $clicks += (int)($link['click_count'] ?? 0);
            }

            $dayStart = strtotime(gmdate('Y-m-d 00:00:00'));
            $commandsToday = 0;
            foreach ($data['events'] as $event) {
                if (!is_array($event)) {
                    continue;
                }
                if (($event['type'] ?? '') !== 'command') {
                    continue;
                }
                $ts = parseTs((string)($event['ts'] ?? ''));
                if ($ts !== null && $ts >= $dayStart) {
                    $commandsToday++;
                }
            }

            return [
                'users' => count($data['users']),
                'active_links' => $active,
                'expired_links' => $expired,
                'deleted_links' => $deleted,
                'total_clicks' => $clicks,
                'commands_today' => $commandsToday,
                'uptime_seconds' => max(0, time() - $startedAt),
            ];
        });
    }

    public function exportJson(): string
    {
        $data = $this->normalizeData($this->loadData());
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function replaceAll(array $incoming): array
    {
        if (!$this->validateImportData($incoming)) {
            return ['ok' => false, 'error' => 'Schema validation failed'];
        }

        $normalized = $this->normalizeData($incoming);
        $ok = $this->saveData($normalized);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Failed to write database'];
        }
        return ['ok' => true];
    }

    public function recordEvent(string $type, string $name, ?int $chatId, array $meta): void
    {
        $this->withLock(function (array &$data) use ($type, $name, $chatId, $meta): void {
            $data['events'][] = [
                'ts' => isoNow(),
                'type' => $type,
                'name' => $name,
                'chat_id' => $chatId,
                'meta' => $meta,
            ];
            if (count($data['events']) > EVENT_LIMIT) {
                $data['events'] = array_slice($data['events'], -EVENT_LIMIT);
            }
        });
    }

    private function ensureUser(array &$data, int $chatId, ?string $username, ?string $firstName): array
    {
        $key = (string)$chatId;
        $now = isoNow();
        if (!isset($data['users'][$key]) || !is_array($data['users'][$key])) {
            $data['users'][$key] = [
                'chat_id' => $chatId,
                'access_enabled' => false,
                'credits' => 0,
                'joined_at' => $now,
                'last_active_at' => $now,
                'username' => $username,
                'first_name' => $firstName,
            ];
        } else {
            $data['users'][$key]['last_active_at'] = $now;
            if ($username !== null && $username !== '') {
                $data['users'][$key]['username'] = $username;
            }
            if ($firstName !== null && $firstName !== '') {
                $data['users'][$key]['first_name'] = $firstName;
            }
        }
        return $data['users'][$key];
    }

    private function expireLinks(array &$data): void
    {
        $now = time();
        foreach ($data['links'] as $code => $link) {
            if (!is_array($link)) {
                continue;
            }
            if (($link['status'] ?? '') !== 'active') {
                continue;
            }
            $ts = parseTs((string)($link['expires_at'] ?? ''));
            if ($ts === null || $ts <= $now) {
                $link['status'] = 'expired';
                $link['last_used_at'] = isoNow();
                $data['links'][$code] = $link;
            }
        }
    }

    private function validateImportData(array $data): bool
    {
        $required = ['settings', 'users', 'links', 'sessions', 'events'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }
        if (!is_array($data['settings']) || !is_array($data['users']) || !is_array($data['links']) || !is_array($data['sessions']) || !is_array($data['events'])) {
            return false;
        }

        foreach ($data['links'] as $code => $link) {
            if (!is_string($code) || preg_match('/^[A-Za-z0-9_-]{3,64}$/', $code) !== 1) {
                return false;
            }
            if (!is_array($link)) {
                return false;
            }
            $url = (string)($link['original_url'] ?? '');
            if (!isValidHttpUrl($url)) {
                return false;
            }
            $status = (string)($link['status'] ?? 'active');
            if (!in_array($status, ['active', 'expired', 'deleted'], true)) {
                return false;
            }
        }

        return true;
    }

    private function defaultData(): array
    {
        return [
            'settings' => [
                'maintenance' => false,
                'code_length' => 6,
                'expiry_days' => 30,
                'created_at' => isoNow(),
            ],
            'users' => [],
            'links' => [],
            'sessions' => [],
            'events' => [],
        ];
    }

    private function normalizeData(array $data): array
    {
        $base = $this->defaultData();

        foreach (['settings', 'users', 'links', 'sessions', 'events'] as $key) {
            if (!array_key_exists($key, $data) || !is_array($data[$key])) {
                continue;
            }
            $base[$key] = $data[$key];
        }

        $base['settings']['maintenance'] = (bool)($base['settings']['maintenance'] ?? false);
        $base['settings']['code_length'] = max(4, min(12, (int)($base['settings']['code_length'] ?? 6)));
        $base['settings']['expiry_days'] = max(1, (int)($base['settings']['expiry_days'] ?? 30));
        $base['settings']['created_at'] = (string)($base['settings']['created_at'] ?? isoNow());

        foreach ($base['users'] as $key => $user) {
            if (!is_array($user)) {
                unset($base['users'][$key]);
                continue;
            }
            $chatId = toIntOrNull($user['chat_id'] ?? $key);
            if ($chatId === null || $chatId <= 0) {
                unset($base['users'][$key]);
                continue;
            }
            $entry = [
                'chat_id' => $chatId,
                'access_enabled' => (bool)($user['access_enabled'] ?? false),
                'credits' => max(0, (int)($user['credits'] ?? 0)),
                'joined_at' => (string)($user['joined_at'] ?? isoNow()),
                'last_active_at' => (string)($user['last_active_at'] ?? isoNow()),
                'username' => isset($user['username']) ? (string)$user['username'] : null,
                'first_name' => isset($user['first_name']) ? (string)$user['first_name'] : null,
            ];
            unset($base['users'][$key]);
            $base['users'][(string)$chatId] = $entry;
        }

        foreach ($base['links'] as $code => $link) {
            if (!is_array($link) || preg_match('/^[A-Za-z0-9_-]{3,64}$/', (string)$code) !== 1) {
                unset($base['links'][$code]);
                continue;
            }
            $entry = [
                'code' => (string)($link['code'] ?? $code),
                'owner_chat_id' => (int)($link['owner_chat_id'] ?? 0),
                'original_url' => (string)($link['original_url'] ?? ''),
                'short_url' => (string)($link['short_url'] ?? ($this->baseShortUrl . '/' . $code)),
                'created_at' => (string)($link['created_at'] ?? isoNow()),
                'expires_at' => (string)($link['expires_at'] ?? isoNow()),
                'status' => (string)($link['status'] ?? 'active'),
                'click_count' => max(0, (int)($link['click_count'] ?? 0)),
                'last_clicked_at' => $link['last_clicked_at'] ?? null,
                'last_used_at' => $link['last_used_at'] ?? null,
            ];
            if (!in_array($entry['status'], ['active', 'expired', 'deleted'], true)) {
                $entry['status'] = 'active';
            }
            $base['links'][$code] = $entry;
        }

        foreach ($base['sessions'] as $key => $session) {
            if (!is_array($session)) {
                unset($base['sessions'][$key]);
                continue;
            }
            $chatId = toIntOrNull($session['chat_id'] ?? $key);
            if ($chatId === null || $chatId <= 0) {
                unset($base['sessions'][$key]);
                continue;
            }
            $base['sessions'][(string)$chatId] = [
                'chat_id' => $chatId,
                'pending' => isset($session['pending']) ? (string)$session['pending'] : null,
                'updated_at' => (string)($session['updated_at'] ?? isoNow()),
            ];
            if ((string)$key !== (string)$chatId) {
                unset($base['sessions'][$key]);
            }
        }

        if (!is_array($base['events'])) {
            $base['events'] = [];
        }
        if (count($base['events']) > EVENT_LIMIT) {
            $base['events'] = array_slice($base['events'], -EVENT_LIMIT);
        }

        return $base;
    }

    private function loadDataNoThrow(): array
    {
        $raw = @file_get_contents($this->dbPath);
        if ($raw === false || $raw === '') {
            return $this->defaultData();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $this->defaultData();
    }

    private function loadData(): array
    {
        return $this->loadDataNoThrow();
    }

    private function saveData(array $data): bool
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return false;
        }

        $tmp = $this->dbPath . '.tmp';
        $ok = @file_put_contents($tmp, $payload, LOCK_EX);
        if ($ok === false) {
            return false;
        }

        return @rename($tmp, $this->dbPath);
    }
}
