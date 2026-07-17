<?php
require_once __DIR__ . '/BBKParser.php';
require_once __DIR__ . '/ALSALAMParser.php';
require_once __DIR__ . '/BRACParser.php';
require_once __DIR__ . '/GenericParser.php';

class ParserFactory {
    private static array $parsers = [
        BBKParser::class,
        ALSALAMParser::class,
        BRACParser::class,
    ];

    public static function detect(string $text): BankParserBase {
        foreach (self::$parsers as $parserClass) {
            $parser = new $parserClass();
            if ($parser->canParse($text)) return $parser;
        }
        return new GenericParser();
    }

    public static function extractTextFromPdf(string $pdfPath): string {
        // Try pdftotext first (fastest)
        $out = shell_exec("pdftotext -layout " . escapeshellarg($pdfPath) . " - 2>/dev/null");
        if ($out && strlen(trim($out)) > 100) return $out;

        // Try using Claude API to extract text from PDF (most accurate)
        if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY) {
            return self::extractWithClaude($pdfPath);
        }

        return '';
    }

    private static function extractWithClaude(string $pdfPath): string {
        $pdfData = base64_encode(file_get_contents($pdfPath));
        $payload = [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 4000,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>$pdfData]],
                    ['type'=>'text','text'=>"Extract ALL transaction data from this bank statement. Return ONLY the raw text content, preserving the table structure with dates, descriptions, amounts and balances. Do not add any commentary."]
                ]
            ]]
        ];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.ANTHROPIC_API_KEY,'anthropic-version: 2023-06-01','anthropic-beta: pdfs-2024-09-25'],
            CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>false,
        ]);
        $resp=curl_exec($ch); curl_close($ch);
        if($resp){
            $d=json_decode($resp,true);
            $text='';
            foreach(($d['content']??[]) as $b) if($b['type']==='text') $text.=$b['text'];
            return $text;
        }
        return '';
    }

    // Use Claude to parse statement intelligently (most accurate)
    public static function parseWithClaude(string $pdfPath, string $currency='BHD'): array {
        $pdfData = base64_encode(file_get_contents($pdfPath));
        $prompt = "This is a bank statement PDF. Extract ALL transactions and return ONLY valid JSON:\n".
            "{\"period_from\":\"YYYY-MM-DD\",\"period_to\":\"YYYY-MM-DD\",\"opening_balance\":0,\"closing_balance\":0,\"currency\":\"{$currency}\",".
            "\"transactions\":[{\"line_date\":\"YYYY-MM-DD\",\"description\":\"text\",\"debit\":0,\"credit\":0,\"balance\":0,\"reference\":\"\"}]}";

        $payload = [
            'model'=>'claude-sonnet-4-6','max_tokens'=>4000,
            'messages'=>[['role'=>'user','content'=>[
                ['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>$pdfData]],
                ['type'=>'text','text'=>$prompt]
            ]]]
        ];
        $ch=curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.ANTHROPIC_API_KEY,'anthropic-version: 2023-06-01','anthropic-beta: pdfs-2024-09-25'],
            CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>false,
        ]);
        $resp=curl_exec($ch); curl_close($ch);
        if(!$resp) return [];
        $d=json_decode($resp,true); $text='';
        foreach(($d['content']??[]) as $b) if($b['type']==='text') $text.=$b['text'];
        $text=preg_replace('/```(?:json)?\s*/i','',$text); $text=preg_replace('/```/','',$text);
        if(preg_match('/\{.*\}/s',$text,$m)){
            $parsed=json_decode($m[0],true);
            if(is_array($parsed)) return $parsed;
        }
        return [];
    }
}
