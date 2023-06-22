<?php declare(strict_types=1);

require_once __DIR__ . '/../../db_helpers.php';
require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Utils\Connection;
use App\Entity\Book;
use App\Domain\BookStats;
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

        $this->book = $this->make_book('hi', 'Hi there.', $this->english);
        $this->texttag = new TextTag();
        $this->texttag->setText("Hola");
        $this->book->addTag($this->texttag);
        $this->book_repo->save($this->book, true);

        BookStats::refresh($this->book_repo);

        $this->term = $this->addTerms($this->english, 'term')[0];
        $this->termtag = TermTag::makeTermTag('termtag');
        $this->term->addTermTag($this->termtag);
        $this->term->setCurrentImage('someimage.jpg');
        $this->term_repo->save($this->term, true);

        DbHelpers::assertRecordcountEquals("books", 1);
        DbHelpers::assertRecordcountEquals("texts", 1);
        DbHelpers::assertRecordcountEquals("booktags", 1);
        DbHelpers::assertRecordcountEquals("tags2", 1);
        DbHelpers::assertRecordcountEquals("words", 1);
        DbHelpers::assertRecordcountEquals("wordtags", 1);
        DbHelpers::assertRecordcountEquals("tags", 1);
    }

    private function assertBookTagsCounts(int $books, int $texts, int $tags2, int $booktags) {
        DbHelpers::assertRecordcountEquals("books", $books, "books");
        DbHelpers::assertRecordcountEquals("texts", $texts, "texts");
        DbHelpers::assertRecordcountEquals("booktags", $booktags, "booktags");
        DbHelpers::assertRecordcountEquals("tags2", $tags2, "tags2");
    }

    private function assertBookTablesEmpty() {
        foreach ([ 'books', 'texts', 'booktags', 'bookstats', 'sentences', 'texttokens' ] as $t)
            DbHelpers::assertRecordcountEquals($t, 0, $t);
    }

    private function assertTermTablesEmpty() {
        foreach ([ 'words', 'wordimages', 'wordflashmessages', 'wordparents', 'wordtags' ] as $t)
            DbHelpers::assertRecordcountEquals($t, 0, $t);
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
        $this->assertBookTablesEmpty();
        $this->assertBookTagsCounts(0, 0, 1, 0);
    }

    /**
     * @group fk_booktags_1
     */
    public function test_booktags_book_sql()
    {
        $this->exec("delete from books where BkID = {$this->book->getId()}");
        $this->assertBookTablesEmpty();
        $this->assertBookTagsCounts(0, 0, 1, 0);
    }

    /**
     * @group fk_booktags
     */
    public function test_booktags_tag_model()
    {
        $this->texttag_repo->remove($this->texttag, true);
        $this->assertBookTagsCounts(1, 1, 0, 0);
    }

    /**
     * @group fk_booktags
     */
    public function test_booktags_tag_sql()
    {
        $this->exec("delete from tags2 where T2ID = {$this->texttag->getId()}");
        $this->assertBookTagsCounts(1, 1, 0, 0);
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
        $this->termtag_repo->remove($this->termtag, true);
        $this->assertWordTagsCounts(1, 0, 0);
    }

    /**
     * @group fk_wordtags
     */
    public function test_wordtags_tag_sql()
    {
        $this->exec("delete from tags where TgID = {$this->termtag->getId()}");
        $this->assertWordTagsCounts(1, 0, 0);
    }


}
