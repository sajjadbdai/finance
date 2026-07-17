<?php
/**
 * Abstract base class for all bank statement parsers
 */
abstract class BankParserBase {
    protected string $text = '';
    protected array  $lines = [];
    protected string $currency = 'BHD';

    abstract public function canParse(string $text): bool;
    abstract public function parse(string $text): array;
    abstract public function getBankName(): string;

    protected function extractText(string $pdfPath): string {
        // Use pdftotext if available, else use raw extraction
        $output = shell_exec("pdftotext -layout " . escapeshellarg($pdfPath) . " - 2>/dev/null");
        if ($output) return $output;
        // Fallback: read raw PDF and extract text between BT/ET markers
        $raw = file_get_contents($pdfPath);
        preg_match_all('/BT\s*(.*?)\s*ET/s', $raw, $m);
        return implode("\n", $m[1] ?? []);
    }

    protected function parseAmount(string $str): float {
        $str = str_replace([',', ' '], '', trim($str));
        return (float)$str;
    }

    protected function parseDate(string $str): ?string {
        $formats = [
            'd/m/Y', 'd-m-Y', 'Y-m-d', 'd M Y', 'd-M-Y',
            'd/m/y', 'j/n/Y', 'Y/m/d', 'd.m.Y'
        ];
        foreach ($formats as $fmt) {
            $d = DateTime::createFromFormat($fmt, trim($str));
            if ($d) return $d->format('Y-m-d');
        }
        // Try strtotime
        $t = strtotime($str);
        if ($t) return date('Y-m-d', $t);
        return null;
    }

    protected function cleanText(string $t): string {
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    public function getResult(string $pdfPath, int $accountId): array {
        $this->text  = $this->extractText($pdfPath);
        $this->lines = explode("\n", $this->text);
        $data = $this->parse($this->text);
        $data['account_id'] = $accountId;
        $data['bank_name']  = $this->getBankName();
        $data['file_path']  = $pdfPath;
        $data['file_name']  = basename($pdfPath);
        $data['parsed_by']  = static::class;
        return $data;
    }
}
