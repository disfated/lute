<?php

namespace App\Domain;

use App\Entity\Book;
use App\Repository\BookRepository;
use App\Repository\TermRepository;
use App\Entity\Language;
use App\Utils\Connection;

class BookStats {

    public static function refresh(BookRepository $book_repo, TermRepository $term_repo) {
        $conn = Connection::getFromEnvironment();
        $books = BookStats::booksToUpdate($conn, $book_repo);
        if (count($books) == 0)
            return;

        $langids = array_map(
            fn($b) => $b->getLanguage()->getLgID(),
            $books);
        $langids = array_unique($langids);

        foreach ($langids as $langid) {
            $langbooks = array_filter(
                $books,
                fn($b) => $b->getLanguage()->getLgID() == $langid);
            foreach ($langbooks as $b) {
                $stats = BookStats::getStats($b, $term_repo, $conn);
                BookStats::updateStats($b, $stats, $conn);
            }
        }
    }

    public static function markStale(Book $book) {
        $conn = Connection::getFromEnvironment();
        $bkid = $book->getId();
        $sql = "delete from bookstats where BkID = $bkid";
        $conn->query($sql);
    }

    public static function recalcLanguage(Language $lang) {
        $conn = Connection::getFromEnvironment();
        $lgid = $lang->getLgId();
        $sql = "delete from bookstats
          where BkID in (select BkID from books where BkLgID = $lgid)";
        $conn->query($sql);
    }

    private static function booksToUpdate($conn, $book_repo): array {
        $sql = "select bkid from books
          where bkid not in (select bkid from bookstats)";
        $bkids = [];
        $res = $conn->query($sql);
        while ($row = $res->fetch(\PDO::FETCH_NUM)) {
            $bkids[] = intval($row[0]);
        }

        // This is not performant, but at the moment I don't care as
        // it's unlikely that there will be many book stats to update.
        $books = [];
        foreach ($bkids as $bkid) {
            $books[] = $book_repo->find($bkid);
        }
        return $books;
    }

    private static function getStats(
        Book $b,
        TermRepository $term_repo,
        $conn
    )
    {
        $count = function($sql) use ($conn) {
            $res = $conn->query($sql);
            $row = $res->fetch(\PDO::FETCH_NUM);
            if ($row == false)
                return 0;
            return intval($row[0]);
        };

        $lgid = $b->getLanguage()->getLgID();
        $bkid = $b->getID();

        // Naive method of getting unknowns: just select terms.
        // This doesn't work well because it doesn't take into
        // account multi-word terms.
        $sql = "select count(distinct toktextlc)
from texttokens
inner join texts on txid = toktxid
inner join books on bkid = txbkid
where toktextlc not in (select wotextlc from words where wolgid = {$lgid})
and tokisword = 1
and txbkid = {$bkid}
group by txbkid";
        $unknowns = $count($sql);
        // dump($sql);
        // dump($unknowns);
        /* */

        /* */
        $getUnknowns = function($text) use ($term_repo) {
            $ss = \App\Domain\RenderableSentence::getSentences($text, $term_repo);
            $alltoks = array_map(fn($s) => $s->renderable(), $ss);
            $alltoks = array_merge([], ...$alltoks);
            $isUnknown = function($ti) { return $ti->IsWord == 1 && $ti->WoStatus == null; };
            $renderedUnks = array_filter($alltoks, $isUnknown);
            $renderedUnks = array_map(fn($ti) => $ti->TextLC, $renderedUnks);
            return array_unique($renderedUnks);
        };
        $unknowns = [];
        $ii = 0;
        foreach ($b->getTexts() as $t) {
            $ii += 1;
            dump($ii);
            $unknowns[] = $getUnknowns($t);
        }
        $unknowns = array_merge([], ...$unknowns);
        $unknowns = array_unique($unknowns);
        // echo implode(', ', $unknowns);
        $unknowns = count($unknowns);
        dump('got count unk = ' . $unknowns);
        /* */
        
        /*
          // in case more stats detail is wanted ...
        $statcount = [];
        foreach ([0, 1, 2, 3, 4, 5, 98, 99] as $sid)
            $statcount[$sid] = 0;

        $ss = App\Domain\RenderableSentence::getSentences($t, $this->term_repo);
        foreach ($ss as $s) {
            $tis = array_filter($s->renderable(), fn($ti) => $ti->IsWord == 1);
            foreach ($tis as $ti) {
                $sid = $ti->WoStatus ?? 0;
                $statcount[$sid] += 1;
            }
        }
        dump($statcount);
        */

        $sql = "select count(distinct toktextlc)
from texttokens
inner join texts on txid = toktxid
inner join books on bkid = txbkid
where tokisword = 1
and txbkid = {$bkid}
group by txbkid";
        $allunique = $count($sql);
        // dump($sql);
        // dump($allunique);

        $sql = "select count(toktextlc)
from texttokens
inner join texts on txid = toktxid
inner join books on bkid = txbkid
where tokisword = 1
and txbkid = {$bkid}
group by txbkid";
        $all = $count($sql);
        // dump($sql);
        // dump($all);

        $percent = 0;
        if ($allunique > 0) // In case not parsed.
            $percent = round(100.0 * $unknowns / $allunique);

        // Any change in the below fields requires a change to
        // updateStats as well, query insert doesn't check field
        // order..
        return [
            $all,
            $allunique,
            $unknowns,
            $percent
        ];
    }

    private static function updateStats($b, $stats, $conn) {
        if ($b->getID() == null)
            return;
        $vals = [
            $b->getId(),
            ...$stats
        ];
        $valstring = implode(',', $vals);
        $sql = "insert or ignore into bookstats
        (BkID, wordcount, distinctterms, distinctunknowns, unknownpercent)
        values ( $valstring )";
        $conn->query($sql);
    }
}
