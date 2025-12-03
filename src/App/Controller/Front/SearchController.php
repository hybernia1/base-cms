<?php
namespace App\Controller\Front;

use App\Service\ContentType;
use RedBeanPHP\R as R;

class SearchController extends BaseFrontController
{
    public function index(): void
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $definitions = ContentType::definitions();

        $posts = [];
        $emptyMessage = 'Zadejte hledaný výraz.';

        if ($query !== '') {
            $likeQuery = '%' . $query . '%';
            $found = R::findAll(
                'content',
                ' status = ? AND deleted_at IS NULL AND (title LIKE ? OR body LIKE ?) ORDER BY created_at DESC ',
                ['published', $likeQuery, $likeQuery]
            );

            $posts = $this->attachAuthors($found);
            $emptyMessage = 'Pro zadaný dotaz se nenašly žádné výsledky.';
        }

        $this->render('front/search.twig', [
            'query' => $query,
            'posts' => $posts,
            'content_types' => $definitions,
            'heading' => 'Hledání',
            'empty_message' => $emptyMessage,
        ]);
    }
}
