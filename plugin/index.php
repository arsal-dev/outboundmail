<?php
declare(strict_types=1);

session_start();

$cpanelBootstrap = '/usr/local/cpanel/php/cpanel.php';
if (is_readable($cpanelBootstrap)) {
    require_once $cpanelBootstrap;
}

if (class_exists('CPAuth') && method_exists('CPAuth', 'is_logged_in') && !\CPAuth::is_logged_in()) {
    http_response_code(403);
    exit('Access denied.');
}

$configFile = '/etc/outboundmail_db.conf';
if (!is_readable($configFile)) {
    http_response_code(500);
    exit('Database configuration missing or unreadable.');
}

$cfg = require $configFile;
if (!is_array($cfg)) {
    http_response_code(500);
    exit('Database configuration invalid.');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function connectPdo(array $cfg): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'] ?? '127.0.0.1',
        (int)($cfg['port'] ?? 3306),
        $cfg['dbname'] ?? 'outbound_mail',
        $cfg['charset'] ?? 'utf8mb4'
    );

    return new PDO(
        $dsn,
        (string)($cfg['user'] ?? ''),
        (string)($cfg['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function validEmail(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validDomain(string $domain): bool
{
    return (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain);
}

function runUapi(string $module, string $function, string $email): array
{
    $cmd = sprintf(
        '/usr/local/cpanel/bin/uapi --output=json %s %s email=%s 2>&1',
        escapeshellarg($module),
        escapeshellarg($function),
        escapeshellarg($email)
    );
    $output = shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return ['ok' => false, 'message' => 'No output from uapi command.'];
    }
    $json = json_decode($output, true);
    $ok = (bool)($json['status'] ?? false);
    $message = (string)($json['messages'][0] ?? $json['errors'][0] ?? trim($output));
    return ['ok' => $ok, 'message' => $message];
}

function suspendOutgoing(string $email): array
{
    return runUapi('Email', 'suspend_outgoing', $email);
}

function unsuspendOutgoing(string $email): array
{
    return runUapi('Email', 'unsuspend_outgoing', $email);
}

$pdo = connectPdo($cfg);
$flash = ['ok' => [], 'err' => []];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $flash['err'][] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'block') {
            $target = strtolower(trim((string)($_POST['target'] ?? '')));
            $blockType = (string)($_POST['block_type'] ?? 'sender');

            if ($blockType === 'sender') {
                if (!validEmail($target)) {
                    $flash['err'][] = 'Invalid sender email.';
                } else {
                    $result = suspendOutgoing($target);
                    if ($result['ok']) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO blocked_senders (target, block_type) VALUES (:target, :block_type)
                             ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP'
                        );
                        $stmt->execute([':target' => $target, ':block_type' => 'sender']);
                        $flash['ok'][] = "Blocked sender {$target}.";
                    } else {
                        $flash['err'][] = "Failed to block {$target}: {$result['message']}";
                    }
                }
            } elseif ($blockType === 'domain') {
                if (!validDomain($target)) {
                    $flash['err'][] = 'Invalid domain.';
                } else {
                    $stmt = $pdo->prepare('SELECT DISTINCT sender FROM messages WHERE sender LIKE :pattern AND sender IS NOT NULL');
                    $stmt->execute([':pattern' => '%@' . $target]);
                    $emails = [];
                    foreach ($stmt->fetchAll() as $row) {
                        $email = strtolower(trim((string)$row['sender']));
                        if (validEmail($email)) {
                            $emails[] = $email;
                        }
                    }
                    $emails = array_values(array_unique($emails));
                    $okCount = 0;
                    $failCount = 0;
                    foreach ($emails as $email) {
                        $res = suspendOutgoing($email);
                        $res['ok'] ? $okCount++ : $failCount++;
                    }
                    $stmt = $pdo->prepare(
                        'INSERT INTO blocked_senders (target, block_type) VALUES (:target, :block_type)
                         ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP'
                    );
                    $stmt->execute([':target' => $target, ':block_type' => 'domain']);
                    $flash['ok'][] = "Domain {$target} marked blocked. Suspended {$okCount} known sender(s).";
                    if ($failCount > 0) {
                        $flash['err'][] = "Failed to suspend {$failCount} sender(s) in {$target}.";
                    }
                }
            } else {
                $flash['err'][] = 'Invalid block type.';
            }
        } elseif ($action === 'unblock') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id, target, block_type FROM blocked_senders WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $entry = $stmt->fetch();

            if (!$entry) {
                $flash['err'][] = 'Blocked entry not found.';
            } else {
                $target = (string)$entry['target'];
                $blockType = (string)$entry['block_type'];

                if ($blockType === 'sender') {
                    $res = unsuspendOutgoing($target);
                    if ($res['ok']) {
                        $del = $pdo->prepare('DELETE FROM blocked_senders WHERE id = :id');
                        $del->execute([':id' => $id]);
                        $flash['ok'][] = "Unblocked sender {$target}.";
                    } else {
                        $flash['err'][] = "Failed to unblock {$target}: {$res['message']}";
                    }
                } else {
                    $stmt = $pdo->prepare('SELECT DISTINCT sender FROM messages WHERE sender LIKE :pattern AND sender IS NOT NULL');
                    $stmt->execute([':pattern' => '%@' . $target]);
                    $emails = [];
                    foreach ($stmt->fetchAll() as $row) {
                        $email = strtolower(trim((string)$row['sender']));
                        if (validEmail($email)) {
                            $emails[] = $email;
                        }
                    }
                    $emails = array_values(array_unique($emails));
                    $okCount = 0;
                    $failCount = 0;
                    foreach ($emails as $email) {
                        $res = unsuspendOutgoing($email);
                        $res['ok'] ? $okCount++ : $failCount++;
                    }

                    $del = $pdo->prepare('DELETE FROM blocked_senders WHERE id = :id');
                    $del->execute([':id' => $id]);
                    $flash['ok'][] = "Domain {$target} unblocked. Unsuspended {$okCount} known sender(s).";
                    if ($failCount > 0) {
                        $flash['err'][] = "Failed to unsuspend {$failCount} sender(s) in {$target}.";
                    }
                }
            }
        } else {
            $flash['err'][] = 'Unknown action.';
        }
    }
}

$query = trim((string)($_GET['q'] ?? ''));
if ($query !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, timestamp, sender, recipient, subject, spam_score, status, message_id
         FROM messages
         WHERE sender LIKE :q OR recipient LIKE :q OR subject LIKE :q
         ORDER BY timestamp DESC
         LIMIT 100'
    );
    $stmt->execute([':q' => '%' . $query . '%']);
} else {
    $stmt = $pdo->query(
        'SELECT id, timestamp, sender, recipient, subject, spam_score, status, message_id
         FROM messages
         ORDER BY timestamp DESC
         LIMIT 100'
    );
}
$messages = $stmt->fetchAll();

$blockedStmt = $pdo->query(
    'SELECT id, target, block_type, created_at
     FROM blocked_senders
     ORDER BY created_at DESC'
);
$blockedList = $blockedStmt->fetchAll();
$autoRefresh = isset($_GET['autorefresh']) ? 1 : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Outbound Email Monitor</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
    <h1>Outbound Email Monitor</h1>

    <?php foreach ($flash['ok'] as $message): ?>
        <div class="notice success"><?php echo h($message); ?></div>
    <?php endforeach; ?>
    <?php foreach ($flash['err'] as $message): ?>
        <div class="notice error"><?php echo h($message); ?></div>
    <?php endforeach; ?>

    <div class="panel">
        <form method="get" class="filters">
            <input type="text" name="q" value="<?php echo h($query); ?>" placeholder="Search sender, recipient, subject">
            <label>
                <input type="checkbox" name="autorefresh" value="1" <?php echo $autoRefresh ? 'checked' : ''; ?>>
                Auto-refresh (30s)
            </label>
            <button type="submit">Apply</button>
        </form>
    </div>

    <div class="panel">
        <h2>Recent Outbound Messages</h2>
        <table>
            <thead>
            <tr>
                <th>Time</th>
                <th>From</th>
                <th>To</th>
                <th>Subject</th>
                <th>Spam Score</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$messages): ?>
                <tr><td colspan="7">No messages found.</td></tr>
            <?php else: ?>
                <?php foreach ($messages as $row): ?>
                    <?php
                    $sender = (string)($row['sender'] ?? '');
                    $domain = '';
                    if (strpos($sender, '@') !== false) {
                        $domain = strtolower(substr($sender, strrpos($sender, '@') + 1));
                    }
                    ?>
                    <tr>
                        <td><?php echo h((string)$row['timestamp']); ?></td>
                        <td><?php echo h($sender); ?></td>
                        <td><?php echo h((string)($row['recipient'] ?? '')); ?></td>
                        <td><?php echo h((string)($row['subject'] ?? '')); ?></td>
                        <td><?php echo h((string)($row['spam_score'] ?? '')); ?></td>
                        <td><?php echo h((string)($row['status'] ?? '')); ?></td>
                        <td>
                            <form method="post" class="inline-form" onsubmit="return confirm('Block this sender/domain?');">
                                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="block">
                                <select name="block_type">
                                    <option value="sender">Sender</option>
                                    <option value="domain">Domain</option>
                                </select>
                                <input type="text" name="target" value="<?php echo h($sender); ?>" data-sender="<?php echo h($sender); ?>" data-domain="<?php echo h($domain); ?>">
                                <button type="submit">Block</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <h2>Blocked Senders / Domains</h2>
        <table>
            <thead>
            <tr>
                <th>Type</th>
                <th>Target</th>
                <th>Blocked At</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$blockedList): ?>
                <tr><td colspan="4">No blocked entries.</td></tr>
            <?php else: ?>
                <?php foreach ($blockedList as $entry): ?>
                    <tr>
                        <td><?php echo h((string)$entry['block_type']); ?></td>
                        <td><?php echo h((string)$entry['target']); ?></td>
                        <td><?php echo h((string)$entry['created_at']); ?></td>
                        <td>
                            <form method="post" class="inline-form" onsubmit="return confirm('Unblock this entry?');">
                                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="unblock">
                                <input type="hidden" name="id" value="<?php echo (int)$entry['id']; ?>">
                                <button class="secondary" type="submit">Unblock</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('form.inline-form').forEach(function (form) {
        var select = form.querySelector('select[name="block_type"]');
        var input = form.querySelector('input[name="target"]');
        if (!select || !input) return;
        select.addEventListener('change', function () {
            var sender = input.getAttribute('data-sender') || '';
            var domain = input.getAttribute('data-domain') || '';
            input.value = select.value === 'domain' && domain ? domain : sender;
        });
    });

<?php if ($autoRefresh): ?>
    setInterval(function () {
        window.location.reload();
    }, 30000);
<?php endif; ?>
})();
</script>
</body>
</html>
