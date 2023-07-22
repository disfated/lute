<?php declare(strict_types=1);

require_once __DIR__ . '/../../db_helpers.php';
require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Entity\Book;
use App\Domain\BookStats;
use App\Domain\TermService;
use App\Domain\ReadingFacade;

final class BookStats_Test extends DatabaseTestBase
{

    public function childSetUp(): void
    {
        $this->load_languages();
    }

    public function test_cache_loads_when_prompted()
    {
        DbHelpers::assertRecordcountEquals("bookstats", 0, "nothing loaded");

        $t = $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);

        DbHelpers::assertRecordcountEquals("bookstats", 0, "still not loaded");

        BookStats::refresh($this->book_repo);
        DbHelpers::assertRecordcountEquals("bookstats", 1, "loaded");
    }

    public function test_stats_smoke_test() {
        $t = $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);
        $b = $t->getBook();
        $this->addTerms($this->spanish, [
            "gato", "TENGO"
        ]);
        BookStats::refresh($this->book_repo);

        $sql = "select 
          BkID, wordcount, distinctterms,
          distinctunknowns, unknownpercent
          from bookstats";
        DbHelpers::assertTableContains(
            $sql,
            [ "{$b->getId()}; 4; 4; 2; 50" ]);
    }

    /**
     * @group issue55
     * If multiterms "cover" the existing text, then it's really fully known.
     */
    public function test_stats_calculates_rendered_text() {
        $t = $this->make_text("Hola.", "Tengo un gato.", $this->spanish);
        $b = $t->getBook();
        $this->addTerms($this->spanish, [ "tengo un" ]);
        BookStats::refresh($this->book_repo);

        $statcount = [];
        foreach ([0, 1, 2, 3, 4, 5, 98, 99] as $sid)
            $statcount[$sid] = 0;

        // HACKING CODE -
        // TODO: separate out calculation of renderable text items
        // from the rest of the stuff in the ReadingFacade.
        // e.g, this could be something like:
        // $rt = new RenderableText(reading_repo);
        // needs reading_repo for getTextTokens, getTermsInText ...
        // but maybe those should go elsewhere:
        // ReadingRepo->getTextTokens change to $text->getTextTokens();
        // ReadingRepo->getTermsinText just uses the term_repo anyway.
        // So,
        // $rt = new TextRenderableCalculator($term_repo);
        // $rt->getRenderableSentences($text) -- is same as facade->getSentences(),
        // and can be used for stats calc.
        $term_service = new TermService($this->term_repo);
        $rf = new ReadingFacade(
            $this->reading_repo,
            $this->text_repo,
            $this->book_repo,
            $term_service,
            $this->termtag_repo
        );
        // TODO: loop through all the texts in the book.
        foreach ($rf->getSentences($t) as $s) {
            $tis = array_filter($s->renderable(), fn($ti) => $ti->IsWord == 1);
            foreach ($tis as $ti) {
                $sid = $ti->WoStatus ?? 0;
                // TODO: rather than just count, should only count uniques.
                // Track elements in array - might get memory-heavy - can copy
                // the data needed (Text, WoStatus) into smaller structs.
                $statcount[$sid] += 1;
            }
        }
        // TODO: better way to handle status IDs - perhaps more public consts
        dump($statcount);

        $sql = "select 
          BkID, wordcount, distinctterms,
          distinctunknowns, unknownpercent
          from bookstats";
        DbHelpers::assertTableContains(
            $sql,
            [ "{$b->getId()}; 3; 3; 0; 0" ]);
    }

    public function test_stats_only_update_existing_books_if_specified() {
        $t = $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);
        $b = $t->getBook();
        $this->addTerms($this->spanish, [
            "gato", "TENGO"
        ]);
        BookStats::refresh($this->book_repo);

        $sql = "select 
          BkID, wordcount, distinctterms,
          distinctunknowns, unknownpercent
          from bookstats";
        DbHelpers::assertTableContains(
            $sql,
            [ "{$b->getId()}; 4; 4; 2; 50" ]);

        $this->addTerms($this->spanish, [
            "hola"
        ]);
        BookStats::refresh($this->book_repo);
        DbHelpers::assertTableContains(
            $sql,
            [ "{$b->getId()}; 4; 4; 2; 50" ],
            "not updated yet"
        );

        BookStats::markStale($b);
        BookStats::refresh($this->book_repo);
        DbHelpers::assertTableContains(
            $sql,
            [ "{$b->getId()}; 4; 4; 1; 25" ],
            "now updated, after marked stale"
        );
    }
        
}
