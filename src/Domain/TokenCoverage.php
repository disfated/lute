<?php

namespace App\Domain;

use App\Entity\Book;
use App\Utils\Connection;

/** Helper class for finding coverage of tokens for a given text string. */
class TokenCoverage {

    private function get_count_before($string, $pos, $zws): int {
        $beforesubstr = mb_substr($string, 0, $pos, 'UTF-8');
        // echo "\n";
        // echo "     get count, string = {$string} \n";
        // echo "     get count, pos = {$pos} \n";
        // echo "     get count, before = {$beforesubstr} \n";
        if ($beforesubstr == '')
            return 0;
        $parts = explode($zws, $beforesubstr);
        $parts = array_filter($parts, fn($s) => $s != '');
        // echo "     get count, parts:\n ";
        // dump($parts);
        $n = count($parts);
        // echo "     get count, result = {$n} \n";
        return $n;
    }

    // Using raw data instead of Term entity, thinking that it will be
    // less memory-intensive.
    private function addCoverage($fulltext, $LC_fulltext, &$parts, $termTextLC, $termTokenCount, $termStatus) {
        $zws = mb_chr(0x200B);
        $len_zws = mb_strlen($zws);
        $tlc = $termTextLC;
        $find_patt = $zws . $tlc . $zws;
        $wordlen = mb_strlen($tlc);

        $LCsubject = $LC_fulltext;
        $LCpatt = $find_patt;

        $curr_index = 0;
        $curr_subject = $fulltext;
        $curr_LCsubject = $LCsubject;

        $pos = mb_strpos($curr_LCsubject, $LCpatt, 0);

        while ($pos !== false) {
            $cb = $this->get_count_before($curr_subject, $pos, $zws);
            $curr_index += $cb;

            for ($i = 0; $i < $termTokenCount; $i++) {
                $parts[$curr_index + $i] = $termStatus;  // matched
            }
            $curr_index += $termTokenCount;

            $pos += $wordlen + 2 * $len_zws;
            $curr_subject = mb_substr($curr_subject, $pos);
            $curr_LCsubject = mb_substr($curr_LCsubject, $pos);
            // echo "\nNext iteration with curr_LCsubject = {$curr_LCsubject}\n";
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
        return array_values($parts);
    }

    private function getTermData(Book $book) {
        $conn = Connection::getFromEnvironment();
        $lgid = $book->getLanguage()->getLgID();
        $sql = "select WoTextLC, WoTokenCount, WoStatus
          from
          words
          where WoLgID = {$lgid}
          order by WoTokenCount, WoTextLC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \Exception($conn->error);
        }
        if (!$stmt->execute()) {
            throw new \Exception($stmt->error);
        }
        return $stmt;
    }

    private function getAllTermData(Book $book) {
        $conn = Connection::getFromEnvironment();
        $lgid = $book->getLanguage()->getLgID();
        $sql = "select WoTokenCount, WoStatus, WoTextLC
          from
          words
          where WoLgID = {$lgid}
          order by WoTokenCount DESC, WoTextLC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \Exception($conn->error);
        }
        if (!$stmt->execute()) {
            throw new \Exception($stmt->error);
        }
        return $stmt->fetchAll(\PDO::FETCH_NUM);
    }

    public function getStats_OLD(Book $book) {
        $fulltext = $this->getFullText($book);
        $LC_fulltext = mb_strtolower($fulltext);
        $parts = $this->getParts($LC_fulltext);
        // dump($parts);

        $res = $this->getTermData($book);
        while($row = $res->fetch(\PDO::FETCH_ASSOC)) {
            $termTextLC = $row['WoTextLC'];
            dump('checking term ' . $termTextLC);
            $termTokenCount = intval($row['WoTokenCount']);
            $termStatus = intval($row['WoStatus']);
            $this->addCoverage($fulltext, $LC_fulltext, $parts, $termTextLC, $termTokenCount, $termStatus);
        }

        // dump($parts);
        $all_statuses = array_filter($parts, fn($e) => ! is_string($e));
        $scount = array_count_values($all_statuses);
        // dump($scount);
        $remaining = array_filter($parts, fn($e) => is_string($e));

        // Joining with a space, rather than '', because sometimes
        // words would be joined together (e.g. "statusif").  Not sure
        // why this was happening, can't be bothered to investigate
        // further.
        $remaining = implode(' ', $remaining);
        $ptokens = $book->getLanguage()->getParsedTokens($remaining);
        $ptwords = array_filter($ptokens, fn($p) => $p->isWord);
        $ptwords = array_map(fn($p) => $p->token, $ptwords);
        $ptwords = array_unique($ptwords);

        $scount[0] = count($ptwords);
        return $scount;
        // $remaining = array_filter(fn($s) => $s != null && $s != '', $this->parts);
        // dump($remaining);
    }

    public function getStats(Book $book) {
        $fulltext = $this->getFullText($book);
        $LC_fulltext = mb_strtolower($fulltext);
        $parts = $this->getParts($LC_fulltext);
        // dump($parts);

        $zws = mb_chr(0x200B);

        // Returns array of arrays, inner array = [ WoTokenCount,
        // WoStatus, WoTextLC ];
        $tdata = $this->getAllTermData($book);
        // dump($tdata);

        $cnum = 0;
        foreach (array_chunk($tdata, 500) as $chunk) {
            $cnum += 1;
            // dump('chunk ' . $cnum);
            $termarray = array_map(fn($c) => $zws . $c[2] . $zws, $chunk);
            $replarray = array_map(
                fn($c) => $zws . str_repeat('LUTE' . $c[1] . $zws, intval($c[0])),
                $chunk
            );
            $LC_fulltext = str_replace($termarray, $replarray, $LC_fulltext);
        }
        // dump($LC_fulltext);

        $remainingtokens = explode($zws, $LC_fulltext);
        $allstatuses = array_map(fn($a) => $a[1], $tdata);
        $allstatuses = array_unique($allstatuses);
        $scounts = [];
        foreach ($allstatuses as $status) {
            $toks = array_filter(
                $remainingtokens,
                fn($s) => $s == 'LUTE' . $status
            );
            $remainingtokens = array_filter(
                $remainingtokens,
                fn($s) => $s != 'LUTE' . $status
            );
            $scounts[$status] = count($toks);
        }
        // dump('---');
        // dump($allstatuses);
        // dump($scounts);

        $remaining = $remainingtokens;

        // Joining with a space, rather than '', because sometimes
        // words would be joined together (e.g. "statusif").  Not sure
        // why this was happening, can't be bothered to investigate
        // further.
        $remaining = implode(' ', $remaining);
        $ptokens = $book->getLanguage()->getParsedTokens($remaining);
        $ptwords = array_filter($ptokens, fn($p) => $p->isWord);
        $ptwords = array_map(fn($p) => $p->token, $ptwords);
        $ptwords = array_unique($ptwords);

        $scounts[0] = count($ptwords);
        // dump($scounts);
        return $scounts;
        // $remaining = array_filter(fn($s) => $s != null && $s != '', $this->parts);
        // dump($remaining);
    }

}
