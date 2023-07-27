<?php

namespace App\Domain;

/** Helper class for finding coverage of tokens for a given text string. */
class TokenCoverage {

    private string $text;
    private string $LCtext;
    private array $parts;

    public function __construct(string $text) {
        $zws = mb_chr(0x200B);
        $parts = explode($zws, $text);
        $parts = array_filter($parts, fn($s) => $s != '');

        $this->text = $text;
        $this->LCtext = mb_strtolower($text);
        $this->parts = $parts;
    }

    private function get_count_before($string, $pos, $zws): int {
        $beforesubstr = mb_substr($string, 0, $pos, 'UTF-8');
        // echo "     get count, string = {$string} \n";
        // echo "     get count, pos = {$pos} \n";
        // echo "     get count, before = {$beforesubstr} \n";
        if ($beforesubstr == '')
            return 0;
        $parts = explode($zws, $beforesubstr);
        $parts = array_filter($parts, fn($s) => $s != '');
        // echo "     get count, parts:\n ";
        // dump($parts) . "\n";
        $n = count($parts);
        // echo "     get count, result = {$n} \n";
        return $n;
    }

    // Using raw data instead of Term entity, thinking that it will be
    // less memory-intensive.
    private function addCoverage(string $term_text_lc, int $term_token_count) {
        $zws = mb_chr(0x200B);
        $len_zws = mb_strlen($zws);
        $tlc = $term_text_lc;
        $find_patt = $zws . $tlc . $zws;
        $wordlen = mb_strlen($tlc);

        $LCsubject = $this->LCtext;
        $LCpatt = $find_patt;

        $curr_index = 0;
        $curr_subject = $this->text;
        $curr_LCsubject = $LCsubject;

        $pos = mb_strpos($curr_LCsubject, $LCpatt, 0);

        while ($pos !== false) {
            $rtext = mb_substr($curr_subject, $pos + $len_zws, $wordlen);
            $cb = TokenLocator::get_count_before($curr_subject, $pos, $zws);
            $curr_index += $cb;

            for ($i = 0; $i < $term_token_count; $i++) {
                $this->parts[$curr_index + $i] = null;  // matched
            }

            $curr_subject = mb_substr($curr_subject, $pos + $len_zws);
            $curr_LCsubject = mb_substr($curr_LCsubject, $pos + $len_zws);
            $pos = mb_strpos($curr_LCsubject, $LCpatt, 0);
        }
    }

    public function calcCoverage($terms) {
        foreach ($terms as $t) {
            $this->addCoverage($t);
        }

        $remaining = array_filter(fn($s) => $s != null && $s != '', $this->parts);
        dump($remaining);
    }

}
