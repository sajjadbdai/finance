<?php
require_once __DIR__ . '/BankParserBase.php';

class BRACParser extends BankParserBase {
    public function getBankName(): string { return 'BRAC Bank'; }

    public function canParse(string $text): bool {
        return stripos($text, 'BRAC Bank') !== false
            || stripos($text, 'brac bank') !== false;
    }

    public function parse(string $text): array {
        $result = [
            'currency'=>'BDT','opening_balance'=>0,'closing_balance'=>0,
            'total_credits'=>0,'total_debits'=>0,'period_from'=>null,
            'period_to'=>null,'statement_date'=>null,'transactions'=>[],
            'status'=>'parsed','parse_notes'=>'',
        ];
        if(preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s+(?:to|-)\s+(\d{2}[\/\-]\d{2}[\/\-]\d{4})/i',$text,$m)){
            $result['period_from']=$this->parseDate($m[1]);
            $result['period_to']=$this->parseDate($m[2]);
            $result['statement_date']=$result['period_to'];
        }
        if(preg_match('/Opening\s*Balance[:\s]*([\d,]+\.?\d*)/i',$text,$m)) $result['opening_balance']=$this->parseAmount($m[1]);
        if(preg_match('/Closing\s*Balance[:\s]*([\d,]+\.?\d*)/i',$text,$m)) $result['closing_balance']=$this->parseAmount($m[1]);
        $txns=[];
        foreach(explode("\n",$text) as $line){
            if(preg_match('/^(\d{2}-\d{2}-\d{4}|\d{2}\/\d{2}\/\d{4})\s+(.{5,60}?)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)$/',$line,$m)){
                $date=$this->parseDate($m[1]); if(!$date) continue;
                $debit=$this->parseAmount($m[3]); $credit=$this->parseAmount($m[4]); $bal=$this->parseAmount($m[5]);
                if($debit>0||$credit>0){
                    $txns[]=['line_date'=>$date,'description'=>$this->cleanText($m[2]),'debit'=>$debit,'credit'=>$credit,'balance'=>$bal,'currency'=>'BDT','raw_text'=>$line];
                    $result['total_debits']+=$debit; $result['total_credits']+=$credit;
                }
            }
        }
        $result['transactions']=$txns; $result['txn_count']=count($txns);
        return $result;
    }
}
