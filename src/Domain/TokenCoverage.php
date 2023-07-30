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

    /**
     * Returns array of matches in same format as preg_match or
     * Ref https://stackoverflow.com/questions/1725227/preg-match-and-utf-8-in-php
     */
    private function pregMatchCapture($pattern, $subject)
    {
        $offset = 0;

        $matchInfo = array();
        $flag      = PREG_OFFSET_CAPTURE;

        // var_dump([$method, $pattern, $subject, $matchInfo, $flag, $offset]);
        $n = preg_match_all($pattern, $subject, $matchInfo, $flag, $offset);

        $positions = [];
        if ($n !== 0 && !empty($matchInfo)) {
            foreach ($matchInfo as $matches) {
                foreach ($matches as $match) {
                    $matchedLength = $match[1];
                    // dump($subject);
                    $positions[] = mb_strlen(mb_strcut($subject, 0, $matchedLength));
                }
            }
        }
        return $positions;
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

        dump('in addCoverage');
        dump('term = ' . $termTextLC . ', sentence = ' . $LCsubject);
        dump('pregmatch ....');
        $mc = $this->pregMatchCapture('/' . $find_patt . '/', $LCsubject);
        dump($mc);

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
          from (
            select SeText from
            sentences
            inner join texts on TxID = SeTxID
            inner join books on BkID = TxBkID
            where BkID = {$bkid}
            order by SeID
            limit 100
          ) src";
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
        // $parts = array_filter($parts, fn($s) => $s != '');
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

    public function getStats(Book $book) {
        $fulltext = $this->getFullText($book);
        $LC_fulltext = mb_strtolower($fulltext);
        $parts = $this->getParts($LC_fulltext);
        // dump($parts);

        dump('parts:');
        dump($parts);
        $map_word_start_to_tok_number = [];
        $currpos = 0;
        $currtok = 0;
        foreach ($parts as $p) {
            $map_word_start_to_tok_number[$currpos] = $currtok;
            $currtok += 1;
            $currpos += mb_strlen($p);
        }
        dump('start to tok:');
        dump($map_word_start_to_tok_number);

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

}
