<?php
require_once __DIR__ . '/BankParserBase.php';

class GenericParser extends BankParserBase {
    public function getBankName(): string { return 'Unknown Bank'; }
    public function canParse(string $text): bool { return true; } // fallback

    public function parse(string $text): array {
        $result = [
            'currency'=>'BHD','opening_balance'=>0,'closing_balance'=>0,
            'total_credits'=>0,'total_debits'=>0,'period_from'=>null,
            'period_to'=>null,'statement_date'=>null,'transactions'=>[],
            'status'=>'parsed','parse_notes'=>'Generic parser used - manual review recommended',
        ];
        // Generic date range detection
        if(preg_match('/(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})\s*(?:to|[-–])\s*(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})/i',$text,$m)){
            $result['period_from']=$this->parseDate($m[1]);
            $result['period_to']=$this->parseDate($m[2]);
            $result['statement_date']=$result['period_to'];
        }
        // Try to extract any date+amount pairs
        $txns=[];
        foreach(explode("\n",$text) as $line){
            if(preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{2,4})\s+(.{5,80}?)\s+([\d,]+\.\d{2,3})\s*$/',$line,$m)){
                $date=$this->parseDate($m[1]); if(!$date) continue;
                $amt=$this->parseAmount($m[3]);
                if($amt>0){
                    $txns[]=['line_date'=>$date,'description'=>$this->cleanText($m[2]),'debit'=>0,'credit'=>$amt,'balance'=>0,'currency'=>'BHD','raw_text'=>$line];
                    $result['total_credits']+=$amt;
                }
            }
        }
        $result['transactions']=$txns; $result['txn_count']=count($txns);
        return $result;
    }
}
