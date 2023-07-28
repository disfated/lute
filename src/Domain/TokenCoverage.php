<?php

namespace App\Domain;

use App\Entity\Book;
use App\Utils\Connection;

/** Helper class for finding coverage of tokens for a given text string. */
class TokenCoverage {

    /*
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
    */

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

    private function getFullText(Book $book) {
        $conn = Connection::getFromEnvironment();
        $bkid = $book->getId();
        $sql = "select GROUP_CONCAT(SeText, '')
          from
          sentences
          inner join texts on TxID = SeTxID
          inner join books on BkID = TxBkID
          where BkID = {$bkid}
          order by SeID";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \Exception($conn->error);
        }
        if (!$stmt->execute()) {
            throw new \Exception($stmt->error);
        }
        $record = $stmt->fetch(\PDO::FETCH_NUM);
        $ret = null;
        if ($record) { 
            $ret = $record[0]; 
        }
        return $ret;
    }

    private function getParts($text) {
        $zws = mb_chr(0x200B);
        $parts = explode($zws, $text);
        $parts = array_filter($parts, fn($s) => $s != '');
        return $parts;
    }

    public function getStats(Book $book) {
        $fulltext = $this->getFullText($book);
        $parts = $this->getParts($fulltext);
        $LC_fulltext = mb_strtolower($fulltext);

        $res = $this->getTermData($book);
        while($row = $res->fetch(\PDO::FETCH_ASSOC)) {
            $termTextLC = $row['WoTextLC'];
            $termTokenCount = intval($row['WoTokenCount']);
            $termStatus = intval($row['WoStatus']);
            $this->addCoverage($fulltext, $LC_fulltext, $parts, $termTextLC, $termTokenCount, $termStatus);
        }

        dump($parts);
        return 'todo';
        // $remaining = array_filter(fn($s) => $s != null && $s != '', $this->parts);
        // dump($remaining);
    }

}
