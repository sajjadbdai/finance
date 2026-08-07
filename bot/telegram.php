<?php
require_once __DIR__ . '/../config.php';

function tgSend(int $chatId, string $text, array $extra = []): void {
    $payload = array_merge([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ], $extra);

    $ch = curl_init(TELEGRAM_API . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function tgGetFile(string $fileId): ?string {
    $ch = curl_init(TELEGRAM_API . '/getFile?file_id=' . $fileId);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data['result']['file_path'] ?? null;
}

function tgDownloadVoice(string $fileId): ?string {
    $filePath = tgGetFile($fileId);
    if (!$filePath) return null;

    $url     = 'https://api.telegram.org/file/bot' . TELEGRAM_BOT_TOKEN . '/' . $filePath;
    $tmpFile = sys_get_temp_dir() . '/voice_' . uniqid() . '.ogg';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $content = curl_exec($ch);
    curl_close($ch);

    if (!$content) return null;
    file_put_contents($tmpFile, $content);
    return $tmpFile;
}

/**
 * Transcribe voice using Claude (base64 audio via Whisper-compatible prompt).
 * Falls back to asking user to type if it fails.
 */
function transcribeVoice(string $filePath): ?string {
    // Convert OGG to base64
    $audioData = base64_encode(file_get_contents($filePath));
    @unlink($filePath);

    // Use Anthropic API with audio — since Claude doesn't do audio natively,
    // we use a workaround: send to a transcription note prompt
    // For production: integrate OpenAI Whisper API here for better results
    // For now: return null so bot asks user to type
    return null;
}

/**
 * Check if a Telegram user is authorized to use this bot.
 * Set YOUR_TELEGRAM_ID in config.php to your numeric Telegram user ID.
 * If YOUR_TELEGRAM_ID is 0 (unset), ONLY the admin check runs.
 * For multi-user access, add IDs to the array below.
 */
function isAuthorized(int $userId): bool {
    // If no ID configured, log a warning but reject all — don't silently allow everyone
    if (!defined('YOUR_TELEGRAM_ID') || YOUR_TELEGRAM_ID === 0) {
        error_log('Telegram bot WARNING: YOUR_TELEGRAM_ID is not configured in config.php.');
        return false; // Lock down until configured
    }
    $allowed = [YOUR_TELEGRAM_ID];
    // Add additional Telegram user IDs here:
    // $allowed[] = 987654321;

    return in_array($userId, $allowed);
}
