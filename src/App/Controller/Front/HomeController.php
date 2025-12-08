<?php
namespace App\Controller\Front;

use RedBeanPHP\R as R;
use App\Service\ContentType;

class HomeController extends BaseFrontController
{

    public function index()
    {
        $posts = R::findAll(
            'content',
            ' type = ? AND status = ? AND publish_at <= ? AND deleted_at IS NULL ORDER BY publish_at DESC ',
            ['post', 'published', date('Y-m-d H:i:s')]
        );
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
            $this->renderNotFound([
                'message' => 'Zvolený typ obsahu neexistuje.',
            ]);
            return;
        }

        $items = R::findAll(
            'content',
            ' type = ? AND status = ? AND publish_at <= ? AND deleted_at IS NULL ORDER BY publish_at DESC ',
            [$typeKey, 'published', date('Y-m-d H:i:s')]
        );
        $items = $this->attachAuthors($items);
        $typeDef = $definitions[$typeKey];

        $breadcrumbs = [
            ['label' => 'Domů', 'url' => '/'],
            ['label' => $typeDef['plural_name'] ?? ($typeDef['menu_label'] ?? ($typeDef['name'] ?? $slug))],
        ];

        $this->render('front/home.twig', [
            'posts' => $items,
            'content_types' => $definitions,
            'heading' => $typeDef['plural_name'] ?? ($typeDef['menu_label'] ?? $typeDef['name'] ?? $slug),
            'empty_message' => 'Pro tento typ zatím nic není publikováno.',
            'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
