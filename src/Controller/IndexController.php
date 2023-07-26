<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Repository\TermRepository;
use App\Domain\BookStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Utils\Connection;
use App\Utils\SqliteHelper;
use App\Utils\ImportCSV;
use App\Utils\AppManifest;
use App\Utils\SqliteBackup;
use App\Repository\SettingsRepository;

class IndexController extends AbstractController
{

    #[Route('/', name: 'app_index', methods: ['GET'])]
    public function index(
        Request $request,
        SettingsRepository $repo,
        BookRepository $bookrepo,
        TermRepository $term_repo
    ): Response
    {
        $m = AppManifest::read();
        $gittag = $m['tag'];

        $bkp = new SqliteBackup($_ENV, $repo);
        $bkp_warning = $bkp->warning();

        if ($bkp->should_run_auto_backup()) {
            return $this->redirectToRoute(
                'app_backup_index',
                [ ],
                Response::HTTP_SEE_OTHER
            );
        }

        $show_import_link = count(ImportCSV::DbLoadedTables()) == 0;
        BookStats::refresh($bookrepo, $term_repo);

        return $this->render('index.html.twig', [
            'isdemodata' => SqliteHelper::isDemoData(),
            'dbisempty' => SqliteHelper::dbIsEmpty(),
            'hasbooks' => SqliteHelper::dbHasBooks(),
            'version' => $gittag,
            'status' => 'Active',  // book status
            'showimportcsv' => $show_import_link,
            'bkp_missing_enabled_key' => $bkp->missing_enabled_key(),
            'bkp_enabled' => $bkp->is_enabled(),
            'bkp_missing_keys' => !$bkp->config_keys_set(),
            'bkp_missing_keys_list' => $bkp->missing_keys(),
            'bkp_show_warning' => $bkp->is_enabled() && ($bkp_warning != ''),
            'bkp_warning' => $bkp_warning,
        ]);
    }

    #[Route('/server_info', name: 'app_server_info', methods: ['GET'])]
    public function server_info(): Response
    {
        $m = AppManifest::read();
        $commit = $m['commit'];
        $gittag = $m['tag'];
        $releasedate = $m['release_date'];
        $conn = Connection::getFromEnvironment();
        $php = phpversion();

        return $this->render('server_info.html.twig', [
            'tag' => $gittag,
            'commit' => $commit,
            'release_date' => $releasedate,
            'php' => $php,
            'symfconn' => $_ENV['DATABASE_URL'],
            'isdev' => ($_ENV['APP_ENV'] == 'dev'),
            'allenv' => getenv(),
            'ENV' => $_ENV,
            'SERVER' => $_SERVER
        ]);
    }

}
