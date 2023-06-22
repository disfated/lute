<?php declare(strict_types=1);

require_once __DIR__ . '/../../db_helpers.php';
require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Utils\Connection;
use App\Entity\Book;
use App\Entity\Language;
use App\Entity\TextTag;
use App\Entity\Text;
use App\Entity\Term;
use App\Entity\TermTag;

/**
 * Overall tests for foreign key cascade deletes.
 */
final class ForeignKeyDeletes_Test extends DatabaseTestBase
{

    private $book;
    private $texttag;
    private $term;
    private $termtag;

    public function childSetUp() {
        $this->english = Language::makeEnglish();
        $this->language_repo->save($this->english, true);

        $b = $this->make_book('hi', 'Hi there.', $this->english);
        $tt = new TextTag();
        $tt->setText("Hola");
        $b->addTag($tt);
        $this->book_repo->save($b, true);
        $this->book = $b;
        $this->texttag = $tt;

        $this->term = $this->addTerms($this->english, 'term')[0];
        $tt = new TermTag();
        $tt->setText("termtag");
        $this->term->addTermTag($tt);
        $this->term_repo->save($this->term, true);
        $this->termtag = $tt;

        $sql = "select BkID, BkTitle, BkLgID from books";
        $expected = [ "{$b->getId()}; hi; {$this->english->getLgId()}" ];
        DbHelpers::assertTableContains($sql, $expected);

        DbHelpers::assertRecordcountEquals("books", 1);
        DbHelpers::assertRecordcountEquals("booktags", 1);
        DbHelpers::assertRecordcountEquals("tags2", 1);
        DbHelpers::assertRecordcountEquals("words", 1);
        DbHelpers::assertRecordcountEquals("wordtags", 1);
        DbHelpers::assertRecordcountEquals("tags", 1);
    }

    private function assertBookTagsCounts(int $books, int $tags2, int $booktags) {
        DbHelpers::assertRecordcountEquals("books", $books, "books");
        DbHelpers::assertRecordcountEquals("booktags", $booktags, "booktags");
        DbHelpers::assertRecordcountEquals("tags2", $tags2, "tags2");
    }

    /** IMPORTANT - have to go the Connection::getFromEnvironment,
     * because we need the pragma set! */
    private function exec($sql) {
        $conn = Connection::getFromEnvironment();
        $conn->exec($sql);
    }

    /**
     * @group fk_booktags
     */
    public function test_booktags_book_model()
    {
        $this->book_repo->remove($this->book, true);
        $this->assertBookTagsCounts(0, 1, 0);
    }

    /**
     * @group fk_booktags
     */
    public function test_booktags_book_sql()
    {
        $this->exec("delete from books where BkID = {$this->book->getId()}");
        $this->assertBookTagsCounts(0, 1, 0);
    }

    /**
     * @group fk_booktags
     */
    public function test_booktags_tag_model()
    {
        $this->texttag_repo->remove($this->texttag, true);
        $this->assertBookTagsCounts(1, 0, 0);
    }

    /**
     * @group fk_booktags
     */
    public function test_booktags_tag_sql()
    {
        $this->exec("delete from tags2 where T2ID = {$this->texttag->getId()}");
        $this->assertBookTagsCounts(1, 0, 0);
    }

    private function assertWordTagsCounts(int $words, int $tags, int $wordtags) {
        DbHelpers::assertRecordcountEquals("words", $words, "words");
        DbHelpers::assertRecordcountEquals("wordtags", $wordtags, "wordtags");
        DbHelpers::assertRecordcountEquals("tags", $tags, "tags");
    }

    /**
     * @group fk_wordtags
     */
    public function test_wordtags_word_model()
    {
        $this->term_repo->remove($this->term, true);
        $this->assertWordTagsCounts(0, 1, 0);
    }

    /**
     * @group fk_wordtags
     */
    public function test_wordtags_word_sql()
    {
        $this->exec("delete from words where WoID = {$this->term->getId()}");
        $this->assertWordTagsCounts(0, 1, 0);
    }

    /**
     * @group fk_wordtags
     */
    public function test_wordtags_tag_model()
    {
        $this->texttag_repo->remove($this->texttag, true);
        $this->assertWordTagsCounts(1, 0, 0);
    }

    /**
     * @group fk_wordtags
     */
    public function test_wordtags_tag_sql()
    {
        $this->exec("delete from tags where TgID = {$this->texttag->getId()}");
        $this->assertWordTagsCounts(1, 0, 0);
    }


}
