<?php
namespace App\Controller\Front;

use RedBeanPHP\R as R;
use App\Service\ContentType;

class HomeController extends BaseFrontController
{

    public function index()
    {
        $posts = R::findAll('content', ' type = ? AND status = ? ORDER BY created_at DESC ', ['post', 'published']);
        $posts = $this->attachAuthors($posts);

        $this->render('front/home.twig', [
            'posts' => $posts,
            'content_types' => ContentType::definitions(),
            'heading' => 'Aktuální příspěvky',
        ]);
    }

    public function listByType(string $slug)
    {
        $definitions = ContentType::definitions();
        $typeKey = ContentType::keyFromSlug($slug);

        if (!$typeKey || !isset($definitions[$typeKey])) {
            http_response_code(404);
            $this->render('front/home.twig', [
                'posts' => [],
                'content_types' => $definitions,
                'heading' => 'Nenalezeno',
                'empty_message' => 'Zvolený typ obsahu neexistuje.',
            ]);
            return;
        }

        $items = R::findAll('content', ' type = ? AND status = ? ORDER BY created_at DESC ', [$typeKey, 'published']);
        $items = $this->attachAuthors($items);
        $typeDef = $definitions[$typeKey];

        $this->render('front/home.twig', [
            'posts' => $items,
            'content_types' => $definitions,
            'heading' => $typeDef['plural_name'] ?? ($typeDef['menu_label'] ?? $typeDef['name'] ?? $slug),
            'empty_message' => 'Pro tento typ zatím nic není publikováno.',
        ]);
    }
}
