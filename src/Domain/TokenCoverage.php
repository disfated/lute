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

    public function getStats(Book $book) {
        $fulltext = $this->getFullText($book);
        $fulltext = str_replace('¶', "\n", $fulltext);
        dump('-----');
        dump($fulltext);
        dump('-----');
        $zws = mb_chr(0x200B);

        // Replace all zws with '' before reparsing: When sentences
        // are stored, extra zws are added at the start and end to
        // facilitate word search matching, since the zws is used as a
        // word boundary.  Removing all of the zws in the text returns
        // it to its "original" state, more or less, so it can be
        // reparsed to get a cleaner set of tokens.
        $fulltext = str_replace($zws, '', $fulltext);
        $parts = $book->getLanguage()->getParsedTokens($fulltext);
        $parts = array_map(fn($p) => $p->token, $parts);
        // Re-add the zws at the start and end to facilitate word
        // search matching.
        $LC_fulltext = $zws . mb_strtolower(implode($zws, $parts)) . $zws;

        // dump('parts:');
        // dump($parts);

        // When searching for terms in the text, we need to mark the
        // corresponding token as found or not.  This next section
        // creates a map of the returned search positions to the
        // underlying token number.
        $map_word_start_to_tok_number = [];
        $currpos = 0;
        $currtok = 0;
        foreach ($parts as $p) {
            // dump('mapping ' . $currpos . ' to ' . $currtok);
            $map_word_start_to_tok_number[$currpos] = $currtok;
            $currtok += 1;
            // Adding 1 to account for the zws at the start of the
            // search pattern.
            $currpos += (mb_strlen($p) + 1);
        }
        dump('start to tok:');
        dump($map_word_start_to_tok_number);

        $res = $this->getTermData($book);
        $allTerms = $res->fetchAll(\PDO::FETCH_ASSOC);

        $n = 0;
        foreach (array_chunk($allTerms, 500) as $chunk) {
            $termTextLCs = array_map(fn($row) => $row['WoTextLC'], $chunk);

            $n += 1;
            dump("checking chunk $n, starts with term : " . $termTextLCs[0]);

            // TODO fix, should only take same token count and same status
            $termTokenCount = intval($chunk[0]['WoTokenCount']);
            $termStatus = intval($chunk[0]['WoStatus']);

            $pattern = "(" . implode("|", $termTextLCs) . ")";
            $pattern = '/' . $zws . $pattern . $zws . '/';
            $matchInfo = array();
            $pmaResult = preg_match_all($pattern, $LC_fulltext, $matchInfo, PREG_OFFSET_CAPTURE, 0);
            $checkMatches = ($pmaResult !== 0 && !empty($matchInfo));
            if ($checkMatches) {
                dump("Match info for $pattern :");
                dump($matchInfo);
                dump("end match info");

                $matches = $matchInfo[0];
                foreach ($matches as $match) {
                    dump("--- checking match:");
                    dump($match);
                    dump("--- end match");
                    $matchedLength = $match[1];
                    $pos = mb_strlen(mb_strcut($LC_fulltext, 0, $matchedLength));
                    dump("got pos = $pos");
                    $toknum = $map_word_start_to_tok_number[$pos];
                    for ($i = 0; $i < $termTokenCount; $i++)
                        $parts[$toknum + $i] = $termStatus;
                }
            } // end $checkMatches

            dump("done chunk $n");
        } // next chunk

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
        dump($remaining);
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
