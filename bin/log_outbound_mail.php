#!/usr/local/cpanel/3rdparty/bin/php
<?php
declare(strict_types=1);

/**
 * Exim transport filter:
 * - read original message from stdin
 * - parse selected headers
 * - log metadata to MySQL
 * - write message unchanged to stdout
 */

$configFile = '/etc/outboundmail_db.conf';
$raw = file_get_contents('php://stdin');
if ($raw === false) {
    $raw = '';
}

function parseHeaders(string $rawMessage): array
{
    $headers = [];
    $splitPos = strpos($rawMessage, "\r\n\r\n");
    if ($splitPos === false) {
        $splitPos = strpos($rawMessage, "\n\n");
    }
    $headerBlock = $splitPos !== false ? substr($rawMessage, 0, $splitPos) : $rawMessage;
    $lines = preg_split("/\r\n|\n|\r/", $headerBlock) ?: [];

    $currentHeader = null;
    foreach ($lines as $line) {
        if ($line === '') {
            break;
        }
        if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
            if ($currentHeader !== null) {
                $headers[$currentHeader] .= ' ' . trim($line);
            }
            continue;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            $headers[$name] = $value;
            $currentHeader = $name;
        }
    }

    return $headers;
}

function extractEmail(?string $headerValue): ?string
{
    if ($headerValue === null || $headerValue === '') {
        return null;
    }
    if (preg_match('/<([^>]+)>/', $headerValue, $match)) {
        return strtolower(trim($match[1]));
    }
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $headerValue, $match)) {
        return strtolower(trim($match[0]));
    }
    return strtolower(trim($headerValue));
}

function extractSpamScore(?string $headerValue): ?float
{
    if ($headerValue === null || $headerValue === '') {
        return null;
    }
    if (preg_match('/-?\d+(?:\.\d+)?/', $headerValue, $match)) {
        return (float)$match[0];
    }
    return null;
}

function safeTruncate(?string $value, int $max): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

try {
    if (!is_readable($configFile)) {
        throw new RuntimeException('DB config unreadable.');
    }
    $cfg = require $configFile;
    if (!is_array($cfg)) {
        throw new RuntimeException('DB config invalid.');
    }

    $headers = parseHeaders($raw);
    $sender = safeTruncate(extractEmail($headers['from'] ?? null), 255);
    $recipient = safeTruncate(extractEmail($headers['to'] ?? null), 255);
    $subject = safeTruncate($headers['subject'] ?? null, 255);
    $messageId = safeTruncate($headers['message-id'] ?? null, 255);
    $spamScore = extractSpamScore($headers['x-spam-score'] ?? null);

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'] ?? '127.0.0.1',
        (int)($cfg['port'] ?? 3306),
        $cfg['dbname'] ?? 'outbound_mail',
        $cfg['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO(
        $dsn,
        (string)($cfg['user'] ?? ''),
        (string)($cfg['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $stmt = $pdo->prepare(
        'INSERT INTO messages (sender, recipient, subject, spam_score, status, message_id)
         VALUES (:sender, :recipient, :subject, :spam_score, :status, :message_id)'
    );
    $stmt->bindValue(':sender', $sender, $sender === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':recipient', $recipient, $recipient === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':subject', $subject, $subject === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':spam_score', $spamScore, $spamScore === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':status', 'sent', PDO::PARAM_STR);
    $stmt->bindValue(':message_id', $messageId, $messageId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();
} catch (Throwable $e) {
    if (function_exists('openlog')) {
        openlog('outboundmail_filter', LOG_PID, LOG_MAIL);
        syslog(LOG_ERR, 'Outbound mail log error: ' . $e->getMessage());
        closelog();
    }
}

echo $raw;
