<?php

namespace App\Domain;

use App\Entity\Book;
use App\Repository\TermRepository;
use App\Utils\Connection;

/** Helper class for finding coverage of tokens for a given text string. */
class TokenCoverage {

    public function getStats(Book $book, TermRepository $term_repo) {

        $getUnknowns = function($text) use ($term_repo) {
            $paras = \App\Domain\RenderableSentence::getParagraphs($text, $term_repo);
            $ss = array_merge([], ...$paras);
            $alltoks = array_map(fn($s) => $s->renderable(), $ss);
            $alltoks = array_merge([], ...$alltoks);
            $isUnknown = function($ti) { return $ti->IsWord == 1 && $ti->WoStatus == null; };
            $renderedUnks = array_filter($alltoks, $isUnknown);
            $renderedUnks = array_map(fn($ti) => $ti->TextLC, $renderedUnks);
            return array_unique($renderedUnks);
        };
        $unknowns = [];
        $ii = 0;
        foreach ($book->getTexts() as $t) {
            $ii += 1;
            // dump($ii);
            $unknowns[] = $getUnknowns($t);
        }
        $unknowns = array_merge([], ...$unknowns);
        $unknowns = array_unique($unknowns);
        // echo implode(', ', $unknowns);
        $unknowns = count($unknowns);

        return [ 0 => $unknowns ];
    }

}
