<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "Starting price update...\n";

require_once __DIR__ . '/../config.php';
echo "Config loaded\n";
require_once __DIR__ . '/../api/db.php';
echo "DB loaded\n";

$updated = 0; $failed = []; $log = [];

function httpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ?: null;
}

function updatePrice(string $symbol, string $exchange, float $price): void {
    global $updated, $log;
    $st = db()->prepare("UPDATE portfolio SET current_price=?, last_updated=NOW() WHERE UPPER(symbol)=? AND exchange=?");
    $st->execute([$price, strtoupper($symbol), $exchange]);
    if ($st->rowCount() === 0) {
        db()->prepare("UPDATE portfolio SET current_price=?, last_updated=NOW() WHERE UPPER(symbol)=?")
           ->execute([$price, strtoupper($symbol)]);
    }
    $updated++;
    $log[] = "✅ [{$exchange}] {$symbol}: {$price}";
    echo date('H:i:s') . " | ✅ [{$exchange}] {$symbol} = {$price}\n";
}

function callGemini(string $prompt, string $key): ?string {
    $models = ['gemini-3.5-flash', 'gemini-2.5-flash'];
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $payload = [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 1024],
            'tools'            => [['google_search' => new stdClass()]],
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Retry once on 503
        if ($code === 503) {
            sleep(3);
            $ch2 = curl_init($url);
            curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
                CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false]);
            $resp = curl_exec($ch2);
            $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
        }

        if ($code === 200 && $resp) {
            $data = json_decode($resp, true);
            $text = '';
            foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
                if (isset($part['text'])) $text .= $part['text'];
            }
            echo "  [{$model}] HTTP {$code} OK\n";
            return $text;
        }
        echo "  [{$model}] HTTP {$code} - trying next\n";
        if ($code === 429) sleep(3);
    }
    return null;
}

function extractPrices(string $text): array {
    $prices = [];

    // Strip markdown fences
    $text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/```/', '', $text);
    $text = trim($text);

    // Try to fix truncated JSON by closing it
    if (substr_count($text, '{') > substr_count($text, '}')) {
        $text .= '}';
    }

    // Try full JSON parse first
    $json = json_decode($text, true);
    if (is_array($json)) {
        foreach ($json as $k => $v) {
            if (is_numeric($v) && (float)$v > 0) {
                $prices[strtoupper(trim($k))] = (float)$v;
            }
        }
        if ($prices) return $prices;
    }

    // Try finding JSON object anywhere in text
    if (preg_match('/\{[^{}]+\}/s', $text, $m)) {
        $json = json_decode($m[0], true);
        if (is_array($json)) {
            foreach ($json as $k => $v) {
                if (is_numeric($v) && (float)$v > 0) {
                    $prices[strtoupper(trim($k))] = (float)$v;
                }
            }
            if ($prices) return $prices;
        }
    }

    // Try key:value pairs like "SYMBOL": 123.45 or "SYMBOL":123.45
    preg_match_all('/"([A-Z0-9]+)"\s*:\s*([\d]+\.?[\d]*)/i', $text, $m2);
    foreach ($m2[1] as $i => $sym) {
        $price = (float)$m2[2][$i];
        if ($price > 0) $prices[strtoupper(trim($sym))] = $price;
    }
    if ($prices) return $prices;

    // Try markdown table: | SYMBOL | price |
    preg_match_all('/\|\s*\*{0,2}([A-Z0-9]+)\*{0,2}\s*\|[^|]*\|\s*([\d\.]+)/i', $text, $m3);
    foreach ($m3[1] as $i => $sym) {
        $price = (float)$m3[2][$i];
        if ($price > 0) $prices[strtoupper(trim($sym))] = $price;
    }

    // Try KEY=VALUE format (one per line)
    preg_match_all('/([A-Z0-9]+)\s*=\s*([\d]+\.?[\d]*)/i', $text, $m4);
    foreach ($m4[1] as $i => $sym) {
        $price = (float)$m4[2][$i];
        if ($price > 0) $prices[strtoupper(trim($sym))] = $price;
    }

    return $prices;
}

// Get all holdings
$holdings = db()->query("SELECT symbol, market, exchange, currency FROM portfolio WHERE quantity > 0 ORDER BY exchange, symbol")->fetchAll();
echo "Holdings loaded: " . count($holdings) . "\n";

$usaStocks = []; $cryptos = []; $bdStocks = [];
foreach ($holdings as $h) {
    $sym  = strtoupper($h['symbol']);
    $exch = strtoupper($h['exchange'] ?? 'DSE');
    if ($h['market'] === 'USA' || $h['market'] === 'UK') $usaStocks[] = $sym;
    elseif ($h['market'] === 'Crypto') $cryptos[] = $sym;
    elseif ($h['market'] === 'BD') $bdStocks[] = ['symbol' => $sym, 'exchange' => $exch];
}

// ── 1. USA STOCKS ─────────────────────────────────────────
if ($usaStocks) {
    echo "\n📈 USA Stocks...\n";
    foreach (array_unique($usaStocks) as $symbol) {
        $resp  = httpGet("https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&range=1d");
        $price = null;
        if ($resp) {
            $data  = json_decode($resp, true);
            $price = $data['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
        }
        if ($price) updatePrice($symbol, 'NYSE', round((float)$price, 4));
        else { $failed[] = "[USA] {$symbol}"; echo "❌ {$symbol}\n"; }
        sleep(1);
    }
}

// ── 2. CRYPTO ─────────────────────────────────────────────
if ($cryptos) {
    echo "\n🪙 Crypto...\n";
    $coinMap = [
        'BTC'=>'bitcoin','BITCOIN'=>'bitcoin',
        'ETH'=>'ethereum','BNB'=>'binancecoin',
        'SOL'=>'solana','XRP'=>'ripple',
        'ADA'=>'cardano','DOGE'=>'dogecoin',
        'SHIB'=>'shiba-inu','SHIBAINU'=>'shiba-inu',
        'MATIC'=>'matic-network','DOT'=>'polkadot',
        'AVAX'=>'avalanche-2','LINK'=>'chainlink',
        'LTC'=>'litecoin','TRX'=>'tron',
        'PEPE'=>'pepe','TON'=>'the-open-network',
    ];
    $ids = []; $symToId = [];
    foreach (array_unique($cryptos) as $sym) {
        $id = $coinMap[$sym] ?? strtolower($sym);
        $ids[] = $id; $symToId[$id] = $sym;
    }
    $resp = httpGet("https://api.coingecko.com/api/v3/simple/price?ids=" . implode(',', $ids) . "&vs_currencies=usd");
    if ($resp) {
        $data = json_decode($resp, true) ?? [];
        foreach ($data as $coinId => $prices) {
            $sym = $symToId[$coinId] ?? strtoupper($coinId);
            if ($prices['usd'] ?? null) updatePrice($sym, 'Crypto', round((float)$prices['usd'], 4));
        }
    }
}

// ── 3. BD STOCKS via Gemini ───────────────────────────────
if ($bdStocks) {
    echo "\n🇧🇩 BD Stocks via Gemini...\n";

    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (!$geminiKey) {
        echo "❌ GEMINI_API_KEY not set in config.php\n";
        foreach ($bdStocks as $s) $failed[] = "[{$s['exchange']}] {$s['symbol']}";
    } else {
        $dseList = [];
        $cseList = [];
        foreach ($bdStocks as $s) {
            if ($s['exchange'] === 'CSE') $cseList[] = $s['symbol'];
            else $dseList[] = $s['symbol'];
        }

        // DSE - split into chunks of 4 to avoid truncation
        if ($dseList) {
            $chunks = array_chunk($dseList, 3);
            foreach ($chunks as $chunk) {
                $syms   = implode(', ', $chunk);
                $prompt = 'Search DSE Bangladesh stock prices for: ' . $syms . '. Return ONLY this exact format, one per line, no other text:
SYMBOL=price
Use = sign between symbol and price. Current prices only.';
                echo "  Fetching DSE: {$syms}\n";
                $text = callGemini($prompt, $geminiKey);
                if ($text) {
                    echo "  Response: " . substr($text, 0, 500) . "\n";
                    $prices = extractPrices($text);
                    echo "  Extracted: " . count($prices) . " prices\n";
                    foreach ($chunk as $sym) {
                        $price = $prices[$sym] ?? null;
                        if ($price && $price > 0) updatePrice($sym, 'DSE', (float)$price);
                        else $failed[] = "[DSE] {$sym}";
                    }
                } else {
                    foreach ($chunk as $s) $failed[] = "[DSE] {$s}";
                }
                sleep(3);
            }
        }

        // CSE
        if ($cseList) {
            $syms   = implode(', ', $cseList);
            $prompt = 'Search CSE Bangladesh stock prices for: ' . $syms . '. Return ONLY this exact format, one per line, no other text:
BEXGSUKUK=63.10
NBL=1.90
Use = sign between symbol and price. Current prices only.';
            echo "  Fetching CSE: {$syms}\n";
            $text = callGemini($prompt, $geminiKey);
            if ($text) {
                echo "  Response: " . substr($text, 0, 200) . "\n";
                $prices = extractPrices($text);
                echo "  Extracted: " . count($prices) . " prices\n";
                foreach ($cseList as $sym) {
                    $price = $prices[$sym] ?? null;
                    if ($price && $price > 0) updatePrice($sym, 'CSE', (float)$price);
                    else $failed[] = "[CSE] {$sym}";
                }
            } else {
                foreach ($cseList as $s) $failed[] = "[CSE] {$s}";
            }
        }
    }
}

// ── Summary + Telegram ────────────────────────────────────
echo "\n" . str_repeat('=', 45) . "\n";
echo "✅ Updated: {$updated} | ❌ Failed: " . count($failed) . "\n";
if ($failed) echo "Failed: " . implode(', ', $failed) . "\n";

if (defined('TELEGRAM_BOT_TOKEN') && defined('YOUR_TELEGRAM_ID') && YOUR_TELEGRAM_ID > 0 && !empty($log)) {
    $msg  = "📈 *Portfolio Prices Updated*\n" . date('d M Y H:i') . "\n\n";
    $msg .= implode("\n", $log);
    if ($failed) {
        $cleanFailed = array_map(function($f) {
            return str_replace(['[DSE] ', '[CSE] ', '[USA] ', '[Crypto] '], '', $f);
        }, $failed);
        $msg .= "\n\n❌ Not found:\n" . implode(', ', $cleanFailed);
    }
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS     => json_encode(['chat_id' => YOUR_TELEGRAM_ID, 'text' => $msg, 'parse_mode' => 'Markdown']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

echo "Done!\n";
