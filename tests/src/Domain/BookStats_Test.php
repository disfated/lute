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

        BookStats::refresh($this->book_repo, $this->term_repo);
        DbHelpers::assertRecordcountEquals("bookstats", 1, "loaded");
    }

    public function test_stats_smoke_test() {
        $t = $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);
        $b = $t->getBook();
        $this->addTerms($this->spanish, [
            "gato", "TENGO"
        ]);
        BookStats::refresh($this->book_repo, $this->term_repo);

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
        BookStats::refresh($this->book_repo, $this->term_repo);

        $sql = "select 
          BkID, wordcount, distinctterms,
          distinctunknowns, unknownpercent
          from bookstats";
        DbHelpers::assertTableContains(
            $sql,
            [ "{$b->getId()}; 3; 3; 1; 33" ]);
    }

    public function test_stats_only_update_existing_books_if_specified() {
        $t = $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);
        $b = $t->getBook();
        $this->addTerms($this->spanish, [
            "gato", "TENGO"
        ]);
        BookStats::refresh($this->book_repo, $this->term_repo);

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
        BookStats::refresh($this->book_repo, $this->term_repo);
        DbHelpers::assertTableContains(
            $sql,
            [ "{$b->getId()}; 4; 4; 2; 50" ],
            "not updated yet"
        );

        BookStats::markStale($b);
        BookStats::refresh($this->book_repo, $this->term_repo);
        DbHelpers::assertTableContains(
            $sql,
            [ "{$b->getId()}; 4; 4; 1; 25" ],
            "now updated, after marked stale"
        );
    }
        
}
