<?php
namespace App\Controller\Front;

use App\Service\ContentType;
use RedBeanPHP\R as R;

class ContentController extends BaseFrontController
{
    public function show(string $typeSlug, string $contentSlug): void
    {
        $typeKey = ContentType::keyFromSlug($typeSlug);
        if (!$typeKey) {
            http_response_code(404);
            $this->render('front/content/detail.twig', [
                'item' => null,
                'type' => null,
            ]);
            return;
        }

        $definitions = ContentType::definitions();
        $typeDef = $definitions[$typeKey] ?? ['name' => $typeSlug, 'slug' => $typeSlug];

        $item = R::findOne(
            'content',
            ' slug = ? AND type = ? AND status = ? ',
            [$contentSlug, $typeKey, 'published']
        );

        $terms = [];
        $thumbnail = null;
        if ($item) {
            $termIds = R::getCol('SELECT term_id FROM content_term WHERE content_id = ?', [$item->id]);
            if ($termIds) {
                $placeholders = implode(',', array_fill(0, count($termIds), '?'));
                $terms = R::findAll('term', ' id IN (' . $placeholders . ') ORDER BY type, name ', $termIds);
            }

            if ($item->thumbnail_id) {
                $loaded = R::load('media', (int) $item->thumbnail_id);
                if ($loaded && $loaded->id) {
                    $thumbnail = $loaded;
                }
            }
        } else {
            http_response_code(404);
        }

        $this->render('front/content/detail.twig', [
            'item' => $item,
            'type' => $typeDef,
            'terms' => $terms,
            'thumbnail' => $thumbnail,
        ]);
    }
}

