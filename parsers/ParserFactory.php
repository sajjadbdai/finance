<?php
class ParserFactory {
    public static string $lastError = '';

    public static function parseWithClaude(string $pdfPath, string $currency='BHD'): array {
        self::$lastError = '';
        if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
            self::$lastError = 'ANTHROPIC_API_KEY not set in config.php';
            return [];
        }
        if (!file_exists($pdfPath)) { self::$lastError = 'File not found'; return []; }
        $sizeMB = round(filesize($pdfPath)/1048576, 2);
        if ($sizeMB > 20) { self::$lastError = "PDF too large ({$sizeMB} MB, max 20MB)"; return []; }

        $pdfData = base64_encode(file_get_contents($pdfPath));
        $prompt = "Extract this bank statement and return ONLY valid JSON with this exact structure: "
            ."{\"bank_name\":\"...\",\"period_from\":\"YYYY-MM-DD\",\"period_to\":\"YYYY-MM-DD\","
            ."\"opening_balance\":0.00,\"closing_balance\":0.00,\"currency\":\"{$currency}\","
            ."\"transactions\":[{\"line_date\":\"YYYY-MM-DD\",\"description\":\"text\","
            ."\"debit\":0.00,\"credit\":0.00,\"balance\":0.00,\"reference\":\"\"}]}"
            ."\nRules: Return ONLY JSON. Use 0 not null. Dates YYYY-MM-DD. Include ALL transactions.";

        $model = defined('ANTHROPIC_MODEL') && ANTHROPIC_MODEL ? ANTHROPIC_MODEL : 'claude-sonnet-4-6';
        $payload = [
            'model'=>$model,
            'max_tokens'=>8000,
            'messages'=>[['role'=>'user','content'=>[
                ['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>$pdfData]],
                ['type'=>'text','text'=>$prompt]
            ]]]
        ];
        $ch=curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.ANTHROPIC_API_KEY,'anthropic-version: 2023-06-01','anthropic-beta: pdfs-2024-09-25'],
            CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>false,
        ]);
        $resp=curl_exec($ch);
        $curlErr=curl_error($ch);
        $httpCode=curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$resp) { self::$lastError = 'API connection failed: ' . $curlErr; return []; }
        $d=json_decode($resp,true);
        if ($httpCode !== 200) {
            self::$lastError = 'API error HTTP ' . $httpCode . ': ' . ($d['error']['message'] ?? substr($resp,0,200));
            return [];
        }
        $text='';
        foreach(($d['content']??[]) as $b) if(($b['type']??'')==='text') $text.=$b['text'];
        if ($text === '') { self::$lastError = 'API returned no text'; return []; }
        $text=preg_replace('/```(?:json)?\s*/i','',$text);
        $text=str_replace('```','',$text);
        if (preg_match('/\{.*\}/s',$text,$m)) {
            $parsed=json_decode($m[0],true);
            if (is_array($parsed)) { $parsed['parsed_by']='claude-ai'; return $parsed; }
            self::$lastError = 'Invalid JSON from API (possibly truncated). First 150 chars: ' . substr($m[0],0,150);
            return [];
        }
        self::$lastError = 'No JSON in API reply: ' . substr($text,0,150);
        return [];
    }
}
