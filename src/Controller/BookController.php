<?php

namespace App\Controller;

use App\Entity\Book;
use App\DTO\BookDTO;
use App\Form\BookDTOType;
use App\Domain\BookStats;
use App\Repository\BookRepository;
use App\Repository\TermRepository;
use App\Repository\TextTagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/book')]
class BookController extends AbstractController
{

    #[Route('/index/{search?}', name: 'app_book_index', methods: ['GET'])]
    public function index(?string $search, BookRepository $repo, TermRepository $term_repo): Response
    {
        BookStats::refresh($repo, $term_repo);
        // Can pass an initial search string.  If nothing is passed, $search = null.
        return $this->render('book/index.html.twig', [
            'status' => 'Active',
            'initial_search' => $search
        ]);
    }

    private function datatables_source(Request $request, BookRepository $repo, $archived = false): JsonResponse
    {
        $parameters = $request->request->all();
        $data = $repo->getDataTablesList($parameters, $archived);
        $data["draw"] = $parameters['draw'];
        return $this->json($data);
    }

    #[Route('/datatables/active', name: 'app_book_datatables_active', methods: ['POST'])]
    public function datatables_active_source(Request $request, BookRepository $repo): JsonResponse
    {
        return $this->datatables_source($request, $repo, false);
    }

    #[Route('/datatables/archived', name: 'app_book_datatables_archived', methods: ['POST'])]
    public function datatables_archived_source(Request $request, BookRepository $repo): JsonResponse
    {
        return $this->datatables_source($request, $repo, true);
    }

    #[Route('/archived', name: 'app_book_archived', methods: ['GET'])]
    public function archived(BookRepository $bookRepository): Response
    {
        return $this->render('book/index.html.twig', [
            'status' => 'Archived'
        ]);
    }


    #[Route('/read/{BkID}', name: 'app_book_read', methods: ['GET'])]
    public function read(Request $request, Book $book): Response
    {
        $currtxid = $book->getCurrentTextID();
        if ($currtxid == 0) {
            $text = $book->getTexts()[0];
            $currtxid = $text->getId();
        }
        return $this->redirectToRoute('app_read', [ 'TxID' => $currtxid ], Response::HTTP_SEE_OTHER);
    }


    private function processNewForm(
        \Symfony\Component\Form\Form $form,
        Request $request,
        BookDTO $bookdto,
        BookRepository $book_repo,
        TextTagRepository $texttag_repo
    ): ?Response
    {
        $form->handleRequest($request);
        if (! $form->isSubmitted())
            return null;
        if (! $form->isValid()) {
            $msg = "Error on submit: " . $form->getErrors(true, false);
            $this->addFlash('notice', $msg);
            return null;
        }

        // ref https://symfony.com/doc/current/controller/upload_file.html
        $textfile = $form->get('TextFile')->getData();
        if ($textfile) {
            $content = file_get_contents($textfile->getPathname());
            $bookdto->Text = $content;
        }

        try {
            $book = $bookdto->createBook();
            $book_repo->save($book, true);
            return $this->redirectToRoute('app_book_read', [ 'BkID' => $book->getId() ], Response::HTTP_SEE_OTHER);
        }
        catch (\Exception $e) {
            $msg = "Error on save: " . $e->getMessage();
            $this->addFlash('notice', $msg);
            return $this->redirectToRoute('app_index', [], Response::HTTP_SEE_OTHER);
        }
    }


    #[Route('/new', name: 'app_book_new', methods: ['GET', 'POST'])]
    public function new(Request $request, BookRepository $book_repo, TextTagRepository $texttag_repo): Response
    {
        $dto = new BookDTO();
        $form = $this->createForm(BookDTOType::class, $dto);
        $resp = $this->processNewForm($form, $request, $dto, $book_repo, $texttag_repo);
        if ($resp != null)
            return $resp;

        $parameters = $request->query->all();
        $import_url = null;
        if (array_key_exists('importurl', $parameters))
            $import_url = trim($parameters['importurl']);
        if ($import_url != null && $import_url != "")
            $this->import_to_dto($import_url, $dto);

        $form = $this->createForm(BookDTOType::class, $dto);
        return $this->renderForm('book/new.html.twig', [
            'bookdto' => $dto,
            'form' => $form,
            'showlanguageselector' => true,
        ]);
    }


    private function import_to_dto($url, &$dto) {
        $s = null;
        try {
            $s = file_get_contents($url);
        }
        catch (\Exception $e) {
            $em = $e->getMessage();
            $msg = "Could not parse $url (error: $em)";
            $this->addFlash('notice', $msg);
        }
        if ($s == null)
            return;

        $dom = new \DOMDocument;
        // https://stackoverflow.com/questions/1148928/
        //   disable-warnings-when-loading-non-well-formed-html-by-domdocument-php
        $dom->loadHTML($s, LIBXML_NOWARNING | LIBXML_NOERROR);

        $tags = explode(' ', 'p h1 h2 h3 h4');
        $queryparts = array_map(fn($t) => '/html/body//' . $t, $tags);
        $q = implode(' | ', $queryparts);
        $xp = new \DOMXPath($dom);
        $nodes = $xp->query($q);

        $content = [];
        foreach($nodes as $node) {
            $content[] = $node->textContent;
        }
        $dto->Text = implode("\n\n", $content);

        $orig_title = $url;
        $title_nodes = $xp->query('/html/head/title');
        if (count($title_nodes) == 1) {
            $orig_title = $title_nodes[0]->textContent;
        }
        $short_title = mb_substr($orig_title, 0, 150);
        if (mb_strlen($orig_title) > 150)
            $short_title .= ' ...';
        $dto->Title = $short_title;

        $dto->SourceURI = $url;
    }


    #[Route('/import_webpage', name: 'app_book_import_webpage', methods: ['GET', 'POST'])]
    public function import_webpage(Request $request): Response
    {
        return $this->renderForm('book/import_webpage.html.twig', []);
    }


    #[Route('/{BkID}/delete', name: 'app_book_delete', methods: ['POST'])]
    public function delete(Request $request, Book $book, BookRepository $bookRepository): Response
    {
        // TODO:security - CSRF token for datatables actions.
        // $tok = $request->request->get('_token');
        // if ($this->isCsrfTokenValid('delete'.$book->getID(), $tok)) {
        //     $bookRepository->remove($book, true);
        // }
        $bookRepository->remove($book, true);
        return $this->redirectToRoute('app_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{BkID}/archive', name: 'app_book_archive', methods: ['POST'])]
    public function archive(Request $request, Book $book, BookRepository $bookRepository): Response
    {
        // TODO:security - CSRF token for datatables actions.
        // $tok = $request->request->get('_token');
        // if ($this->isCsrfTokenValid('archive'.$book->getID(), $tok)) {
        //     $book->setArchived(true);
        //     $bookRepository->save($book, true);
        // }
        $book->setArchived(true);
        $bookRepository->save($book, true);
        return $this->redirectToRoute('app_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{BkID}/unarchive', name: 'app_book_unarchive', methods: ['POST'])]
    public function unarchive(Request $request, Book $book, BookRepository $bookRepository): Response
    {
        // TODO:security - CSRF token for datatables actions.
        // $tok = $request->request->get('_token');
        // if ($this->isCsrfTokenValid('unarchive'.$book->getID(), $tok)) {
        //     $book->setArchived(false);
        //     $bookRepository->save($book, true);
        // }
        $book->setArchived(false);
        $bookRepository->save($book, true);
        return $this->redirectToRoute('app_book_archived', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{BkID}/reparse', name: 'app_book_reparse', methods: ['POST'])]
    public function reparse(Request $request, Book $book): Response
    {
        // TODO:security - CSRF token for datatables actions.
        // $tok = $request->request->get('_token');
        // if ($this->isCsrfTokenValid('archive'.$book->getID(), $tok)) {
        //     $book->setArchived(true);
        //     $bookRepository->save($book, true);
        // }
        $book->fullParse();
        return $this->redirectToRoute('app_index', [], Response::HTTP_SEE_OTHER);
    }


    private function processEditForm(
        \Symfony\Component\Form\Form $form,
        Request $request,
        BookDTO $bookdto,
        Book $book,
        BookRepository $book_repo,
        TextTagRepository $texttag_repo
    ): ?Response
    {
        $form->handleRequest($request);
        if (! $form->isSubmitted())
            return null;
        if (! $form->isValid()) {
            $msg = "Error on submit: " . $form->getErrors(true, false);
            $this->addFlash('notice', $msg);
            return null;
        }

        try {
            $bookdto->loadBook($book);
            $book_repo->save($book, true);
            return $this->redirectToRoute('app_index', [], Response::HTTP_SEE_OTHER);
        }
        catch (\Exception $e) {
            $msg = "Error on save: " . $e->getMessage();
            $this->addFlash('notice', $msg);
            return $this->redirectToRoute('app_index', [], Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/{BkID}/edit', name: 'app_book_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Book $book, BookRepository $book_repo, TextTagRepository $texttag_repo): Response
    {
        $dto = $book->createBookDTO();
        $form = $this->createForm(BookDTOType::class, $dto);
        $resp = $this->processEditForm($form, $request, $dto, $book, $book_repo, $texttag_repo);
        if ($resp != null)
            return $resp;

        $form = $this->createForm(BookDTOType::class, $dto);
        return $this->renderForm('book/edit.html.twig', [
            'bookdto' => $dto,
            'form' => $form
        ]);
    }

}
