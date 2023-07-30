<?php

namespace App\Repository;

use App\Entity\Term;
use App\Entity\Text;
use App\Entity\Language;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;


/**
 * @extends ServiceEntityRepository<Term>
 *
 * @method Term|null find($id, $lockMode = null, $lockVersion = null)
 * @method Term|null findOneBy(array $criteria, array $orderBy = null)
 * @method Term[]    findAll()
 * @method Term[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TermRepository extends ServiceEntityRepository
{

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Term::class);
    }

    public function save(Term $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Term $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function flush(): void
    {
        $em = $this->getEntityManager();
        $em->flush();
    }

    public function clear(): void
    {
        $em = $this->getEntityManager();
        $em->clear();
    }

    public function detach(Term $t): void
    {
        $this->getEntityManager()->detach($t);
    }

    public function stopSqlLog(): void
    {
        $this->getEntityManager()->
            getConnection()->
            getConfiguration()->
            setSQLLogger(null);
    }

    /**
     * Find a term by an exact match with the specification (only
     * looks at Text and Language).
     */
    public function findBySpecification(Term $specification): ?Term {
        // Using Doctrine Query Language --
        // Interesting, but am not totally confident with it.
        // e.g. That I had to use the private field WoTextLC
        // instead of the public property was surprising.
        // Anyway, it works. :-P
        $dql = "SELECT t FROM App\Entity\Term t
        LEFT JOIN App\Entity\Language L WITH L = t.language
        WHERE L.LgID = :langid AND t.WoTextLC = :val";
        $query = $this->getEntityManager()
               ->createQuery($dql)
               ->setParameter('langid', $specification->getLanguage()->getLgID())
               ->setParameter('val', mb_strtolower($specification->getText()));
        $terms = $query->getResult();

        if (count($terms) == 0)
            return null;
        return $terms[0];
    }

    /**
     * Find Terms by text.
     */
    public function findLikeSpecification(Term $specification, int $maxResults = 50): array
    {
        $search = mb_strtolower(trim($specification->getText() ?? ''));
        if ($search == '')
            return [];

        $dql = "SELECT t FROM App\Entity\Term t
        JOIN App\Entity\Language L WITH L = t.language
        WHERE L.LgID = :langid AND t.WoTextLC LIKE :search
        ORDER BY t.WoTextLC";
        $query = $this->getEntityManager()
               ->createQuery($dql)
               ->setParameter('langid', $specification->getLanguage()->getLgID())
               ->setParameter('search', $search . '%')
               ->setMaxResults($maxResults);
        $raw = $query->getResult();

        // Exact match goes to top.
        $ret = array_filter($raw, fn($r) => $r->getTextLC() == $search);

        // Parents in next.
        $parents = array_filter(
            $raw,
            fn($r) => $r->getChildren()->count() > 0 && $r->getTextLC() != $search
        );
        $ret = array_merge($ret, $parents);

        $remaining = array_filter(
            $raw,
            fn($r) => $r->getTextLC() != $search && $r->getChildren()->count() == 0
        );
        return array_merge($ret, $remaining);
    }


    public function findTermsInText(Text $t) {
        $wids = [];
        $conn = $this->getEntityManager()->getConnection();

        // Querying all words that match the text is very slow, so
        // breaking it up into two parts.

        // 1. Get all exact matches from the tokens.
        $lgid = $t->getLanguage()->getLgID();
        $sql = "select distinct WoID from words
            where wotextlc in (select TokTextLC from texttokens where toktxid = {$t->getID()})
            and WoTokenCount = 1 and WoLgID = $lgid";
        $res = $conn->executeQuery($sql);
        while ($row = $res->fetchNumeric()) {
            $wids[] = $row[0];
        }

        // 2. Get multiword terms that likely match (don't bother
        // checking word boundaries).  Sqlite doesn't support
        // "select LOWER(field)", so do a big select of the tokens.
        $sql = "select WoID from words
            where WoTokenCount > 1 AND WoLgID = $lgid AND
            instr(
              (
                select GROUP_CONCAT(TokTextLC, '')
                from (
                  select TokTextLC from texttokens
                  where TokTxID = {$t->getID()}
                  order by TokOrder
                ) src
              ),
              replace(WoTextLC, char(0x200B), '')
            ) > 0";
        $res = $conn->executeQuery($sql);
        while ($row = $res->fetchNumeric()) {
            $wids[] = $row[0];
        }

        // Add any term parents, they might be needed when loading the
        // actual terms.
        $sql = "select WpParentWoID from wordparents where WpWoID in (?)";
        $res = $conn->executeQuery($sql, array($wids), array(\Doctrine\DBAL\Connection::PARAM_INT_ARRAY));
        while ($row = $res->fetchNumeric()) {
            $wids[] = $row[0];
        }
        
        $dql = "SELECT t, tt, ti, tp, tpt, tpi
          FROM App\Entity\Term t
          LEFT JOIN t.termTags tt
          LEFT JOIN t.images ti
          LEFT JOIN t.parents tp
          LEFT JOIN tp.termTags tpt
          LEFT JOIN tp.images tpi
          WHERE t.id in (:tids)";
        $query = $this->getEntityManager()
               ->createQuery($dql)
               ->setParameter('tids', $wids);
        $raw = $query->getResult();
        return $raw;
    }


    public function findTermsInParsedTokens($tokens, Language $lang) {
        $lgid = $lang->getLgID();
        $wids = [];
        $conn = $this->getEntityManager()->getConnection();

        // Querying all words that match the text is very slow, so
        // breaking it up into two parts.

        // 1. Get all exact matches from the tokens.
        $tokstrings = array_map(fn($t) => mb_strtolower($t->token), $tokens);
        $tokstrings = array_unique($tokstrings);
        $sql = "select distinct WoID from words
            where wotextlc in (?)
            and WoTokenCount = 1 and WoLgID = $lgid";
        $res = $conn->executeQuery($sql, array($tokstrings), array(\Doctrine\DBAL\Connection::PARAM_STR_ARRAY));
        while ($row = $res->fetchNumeric()) {
            $wids[] = $row[0];
        }

        // 2. Get multiword matches.
        $zws = mb_chr(0x200B); // zero-width space.
        $is = array_map(fn($t) => $t->token, $tokens);
        $s = $zws . implode($zws, $is) . $zws;
        $s = mb_strtolower($s);
        $sql = "select WoID from words
            where WoLgID = $lgid AND
            WoTokenCount > 1 AND
            instr(:contentLC, char(0x200B) || WoTextLC || char(0x200B)) > 0";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue("contentLC", $s);
        $res = $stmt->executeQuery();
        while ($row = $res->fetchNumeric()) {
            $wids[] = $row[0];
        }

        $dql = "SELECT t, tt, ti, tp, tpt, tpi
          FROM App\Entity\Term t
          LEFT JOIN t.termTags tt
          LEFT JOIN t.images ti
          LEFT JOIN t.parents tp
          LEFT JOIN tp.termTags tpt
          LEFT JOIN tp.images tpi
          WHERE t.id in (:tids)";
        $query = $this->getEntityManager()
               ->createQuery($dql)
               ->setParameter('tids', $wids);
        $terms = $query->getResult();
        return $terms;
    }


    /** Returns data for ajax paging. */
    public function getDataTablesList($parameters) {

        $base_sql = "SELECT
0 as chk, w.WoID as WoID, LgName, L.LgID as LgID, w.WoText as WoText, p.WoText as ParentText, w.WoTranslation,
replace(wi.WiSource, '.jpeg', '') as WiSource,
ifnull(tags.taglist, '') as TagList,
StText,
StID
FROM
words w
INNER JOIN languages L on L.LgID = w.WoLgID
INNER JOIN statuses S on S.StID = w.WoStatus
LEFT OUTER JOIN wordparents on WpWoID = w.WoID
LEFT OUTER JOIN words p on p.WoID = WpParentWoID
LEFT OUTER JOIN (
  SELECT WtWoID as WoID, GROUP_CONCAT(TgText, ', ') AS taglist
  FROM
  (
    select WtWoID, TgText
    from wordtags wt
    INNER JOIN tags t on t.TgID = wt.WtTgID
    order by TgText
  ) tagssrc
  GROUP BY WtWoID
) AS tags on tags.WoID = w.WoID
LEFT OUTER JOIN wordimages wi on wi.WiWoID = w.WoID
";

        // Extra search filters passed in as data from term/index.html.twig.
        $filtParentsOnly = $parameters['filtParentsOnly'];
        $filtAgeMin = trim($parameters['filtAgeMin']);
        $filtAgeMax = trim($parameters['filtAgeMax']);
        $filtStatusMin = intval($parameters['filtStatusMin']);
        $filtStatusMax = intval($parameters['filtStatusMax']);
        $filtIncludeIgnored = $parameters['filtIncludeIgnored'];

        $wheres = [ "1 = 1" ];
        if ($filtParentsOnly == 'true')
            $wheres[] = "WpWoID IS NULL";
        if ($filtAgeMin != "") {
            $filtAgeMin = intval('0' . $filtAgeMin);
            $wheres[] = "cast(julianday('now') - julianday(w.wocreated) as int) >= $filtAgeMin";
        }
        if ($filtAgeMax != "") {
            $filtAgeMax = intval('0' . $filtAgeMax);
            $wheres[] = "cast(julianday('now') - julianday(w.wocreated) as int) <= $filtAgeMax";
        }

        $statuswheres = [ "StID <> 98" ];  // Exclude "ignored" terms at first.
        if ($filtStatusMin > 0) {
            $statuswheres[] = "StID >= $filtStatusMin";
        }
        if ($filtStatusMax > 0) {
            $statuswheres[] = "StID <= $filtStatusMax";
        }
        $statuswheres = implode(' AND ', $statuswheres);
        if ($filtIncludeIgnored == 'true') {
            $statuswheres = "(({$statuswheres}) OR StID = 98)";
        }
        $wheres[] = $statuswheres;

        $where = implode(' AND ', $wheres);

        $full_base_sql = $base_sql . ' WHERE ' . $where;

        $conn = $this->getEntityManager()->getConnection();
        return DataTablesSqliteQuery::getData($full_base_sql, $parameters, $conn);
    }

}
