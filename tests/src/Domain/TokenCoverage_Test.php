<?php declare(strict_types=1);

require_once __DIR__ . '/../../db_helpers.php';
require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Entity\Book;
use App\Domain\BookStats;
use App\Domain\TermService;
use App\Domain\ReadingFacade;
use App\Domain\TokenCoverage;

final class TokenCoverage_Test extends DatabaseTestBase
{

    public function childSetUp(): void
    {
        $this->load_languages();
    }

    public function test_stats_calculates_rendered_text() {
        $t = $this->make_text("Hola.", "Tengo un gato.  Tengo un perro.", $this->spanish);
        $b = $t->getBook();
        $this->addTerms($this->spanish, [ "tengo un" ]);

        $tc = new TokenCoverage();
        $stats = $tc->getStats($b);
        dump($stats);
    }

}
