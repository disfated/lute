<?php

namespace App\Domain;

use App\Entity\Book;
use App\Repository\TermRepository;
use App\Utils\Connection;
use App\DTO\TextToken;

/** Helper class for finding coverage of tokens for a given text string. */
class TokenCoverage {

    /*
    private function getUnknowns($text, $term_repo) {
        $paras = \App\Domain\RenderableSentence::getParagraphs($text, $term_repo);
        $ss = array_merge([], ...$paras);
        $alltoks = array_map(fn($s) => $s->renderable(), $ss);
        $alltoks = array_merge([], ...$alltoks);
        $isUnknown = function($ti) { return $ti->IsWord == 1 && $ti->WoStatus == null; };
        $renderedUnks = array_filter($alltoks, $isUnknown);
        $renderedUnks = array_map(fn($ti) => $ti->TextLC, $renderedUnks);
        return array_unique($renderedUnks);
    }

    public function getStats_original(Book $book, TermRepository $term_repo) {
        $unknowns = [];
        $ii = 0;
        foreach ($book->getTexts() as $t) {
            $ii += 1;
            // dump($ii);
            $unknowns[] = $this->getUnknowns($t, $term_repo);
        }
        $unknowns = array_merge([], ...$unknowns);
        $unknowns = array_unique($unknowns);
        // echo implode(', ', $unknowns);
        $unknowns = count($unknowns);

        return [ 0 => $unknowns ];
    }
    */

    private function getFullText(Book $book) {
        $conn = Connection::getFromEnvironment();
        $bkid = $book->getId();
        $sql = "select GROUP_CONCAT(TxText, char(10))
          from (
            select TxText from
            texts
            inner join books on BkID = TxBkID
            where BkID = {$bkid}
            order by TxID
          ) src";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $record = $stmt->fetch(\PDO::FETCH_NUM);
        return $record[0];
    }

    private function getParsedTokens($book) {
        $ft = $this->getFullText($book);
        return $book->getLanguage()->getParsedTokens($ft);
    }

    private function getFullTextChunks($parsedtokens) {
        $zws = mb_chr(0x200B); // zero-width space.
        $sgi = new SentenceGroupIterator($parsedtokens, 2000);
        $ret = [];
        while ($i = $sgi->next()) {
            $is = array_map(fn($t) => $t->token, $i);
            $ret[] = $zws . implode($zws, $is) . $zws;
        }
        return $ret;
    }

    private function createTextTokens($parsedTokens) {
        $ret = [];
        $i = 0;
        foreach ($parsedTokens as $pt) {
            $tok = new TextToken();
            $tok->TokSentenceNumber = 1;
            $tok->TokParagraphNumber = 1;
            $tok->TokOrder = $i;
            $i += 1;
            $tok->TokIsWord = $pt->isWord;
            $tok->TokText = $pt->token;
            $tok->TokTextLC = mb_strtolower($pt->token);
            $ret[] = $tok;
        }
        return $ret;

    }
    
    private function getUniqueUnknowns($renderable) {
        $isUnknown = function($ti) { return $ti->IsWord == 1 && $ti->WoStatus == null; };
        $renderedUnks = array_filter($renderable, $isUnknown);
        $renderedUnks = array_map(fn($ti) => $ti->TextLC, $renderedUnks);
        $renderedUnks = array_unique($renderedUnks);
        return $renderedUnks;
    }
    
    public function getStats(Book $book, TermRepository $term_repo) {
        $pt = $this->getParsedTokens($book);
        $sgi = new SentenceGroupIterator($pt, 500);

        $unks = [];
        $counter = 0;
        $maxcount = $sgi->count();
        while ($tokens = $sgi->next()) {
            $term_repo->stopSqlLog();

            $counter += 1;
            // dump('-----');
            $dump = [];
            $dump[] = 'stats for group ' . $counter . ' of ' . $maxcount;
            $time_start = microtime(true);
            $time_now = microtime(true);
            $terms = $term_repo->findTermsInParsedTokens($tokens, $book->getLanguage());
            $dump[] = 'find terms: ' . (microtime(true) - $time_now);

            // dump('create tokens');
            $tts = $this->createTextTokens($tokens);
            $time_now = microtime(true);
            $renderable = RenderableCalculator::getRenderable($terms, $tts);
            $dump[] = 'renderable calc: ' . (microtime(true) - $time_now);

            // dump($renderable);
            // dump('make text items');
            $time_now = microtime(true);
            $textitems = array_map(
                fn($i) => $i->makeTextItem(1, 1, 1, $book->getLanguage()->getLgID()),
                $renderable
            );
            $dump[] = 'make textitems: ' . (microtime(true) - $time_now);

            // dump('done make text items');
            // dump('text times:');
            // dump($textitems);
            $time_now = microtime(true);
            $unks[] = $this->getUniqueUnknowns($textitems);
            $dump[] = 'unique unknowns: ' . (microtime(true) - $time_now);

            // dump($dump);

            $term_repo->flush();
            $term_repo->clear();
        }

        $unks = array_merge([], ...$unks);
        $unks = array_unique($unks);
        // dump('count of unknowns');
        // dump(count($unks));
        return [ 0 => count($unks) ];
    }

}
