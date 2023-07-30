<?php declare(strict_types=1);

require_once __DIR__ . '/../../db_helpers.php';
require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Entity\Book;
use App\Entity\Term;
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

    public function addTerm(string $s, int $status) {
        $term_svc = new TermService($this->term_repo);
        $term = new Term($this->spanish, $s);
        $term->setStatus($status);
        $term_svc->add($term, true);
    }

    public function scenario(string $fulltext, $terms_and_statuses) {
        $t = $this->make_text("Hola.", $fulltext, $this->spanish);
        $b = $t->getBook();

        foreach ($terms_and_statuses as $ts)
            $this->addTerm($ts[0], $ts[1]);

        $tc = new TokenCoverage();
        $stats = $tc->getStats($b);
        dump($stats);
    }

    public function WIP_test_single_word() {
        $this->scenario("Tengo un gato.  Tengo un perro.",
                        [[ "gato", 1 ]]);
    }

    public function test_two_words() {
        $this->scenario("Tengo un gato.  Tengo un perro.",
                        [[ "gato", 1 ], [ "perro", 2 ]]);
    }

    public function WIP_test_with_multiword() {
        $this->scenario("Tengo un gato.  Tengo un perro.",
                        [[ "tengo un", 1 ]]);
    }


}
