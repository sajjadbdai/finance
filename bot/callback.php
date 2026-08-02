<?php
/**
 * DEPRECATED — DO NOT USE.
 *
 * This file is an older standalone version of handleCallback that is
 * superseded by the inline handleCallback() in webhook.php.
 *
 * KEEPING this file for reference only.
 * Loading it alongside webhook.php or oldwebhook.php will cause:
 *   PHP Fatal error: Cannot redeclare handleCallback()
 *
 * The current webhook.php has this function built-in.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/parser.php';
require_once __DIR__ . '/telegram.php';

/** @deprecated Use handleCallback() in webhook.php */
function handleCallback_deprecated(array $cb): void {
    $chatId   = $cb['message']['chat']['id'];
    $msgId    = $cb['message']['message_id'];
    $data     = $cb['data'];
    $callbackId = $cb['id'];

    // Acknowledge callback
    $ch = curl_init(TELEGRAM_API . '/answerCallbackQuery');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['callback_query_id' => $callbackId]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    curl_exec($ch); curl_close($ch);

    // Get session
    $st = db()->prepare("SELECT state, context FROM bot_sessions WHERE telegram_id=?");
    $st->execute([$chatId]);
    $session = $st->fetch();

    if (!$session || $session['state'] !== 'confirm') {
        tgSend($chatId, "Session expired. Please re-send your transaction.");
        return;
    }

    $ctx = json_decode($session['context'], true);

    if ($data === 'confirm_yes') {
        $txnId = saveTransaction($ctx['parsed'], $ctx['source'] ?? 'telegram', $ctx['raw'] ?? '');

        if ($txnId) {
            // Get updated balance
            $acc = getAccountByName($ctx['parsed']['account'] ?? '');
            $bal = $acc ? money((float)$acc['balance'], $acc['currency']) : '—';
            $cur = $acc['currency'] ?? '';

            $type   = strtoupper($ctx['parsed']['type'] ?? '');
            $amount = money((float)($ctx['parsed']['amount'] ?? 0), ($ctx['parsed']['currency'] ?? 'BHD'));
            $currency = $ctx['parsed']['currency'] ?? '';

            tgSend($chatId,
                "✅ <b>Saved! #{$txnId}</b>\n\n" .
                "{$type}: {$amount} {$currency}\n" .
                "📂 " . ($ctx['parsed']['category'] ?? '') . "\n" .
                "🏦 " . ($ctx['parsed']['account'] ?? '') . "\n\n" .
                "💰 New balance: <b>{$bal} {$cur}</b>"
            );
        } else {
            tgSend($chatId, "❌ Save failed — account not found. Check account name.");
        }
    } else {
        tgSend($chatId, "❌ Cancelled.");
    }

    // Clear session
    db()->prepare("UPDATE bot_sessions SET state='idle', context='' WHERE telegram_id=?")
       ->execute([$chatId]);

    // Remove inline keyboard from original message
    $editPayload = [
        'chat_id'      => $chatId,
        'message_id'   => $msgId,
        'reply_markup' => json_encode(['inline_keyboard' => []])
    ];
    $ch = curl_init(TELEGRAM_API . '/editMessageReplyMarkup');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode($editPayload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    curl_exec($ch); curl_close($ch);
}
