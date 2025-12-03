<?php
namespace App\Controller\Front;

use App\Service\ContentType;
use App\Service\TermType;
use RedBeanPHP\R as R;

class TermController extends BaseFrontController
{
    public function show(string $typeKey, string $termSlug): void
    {
        $typeKey = trim($typeKey);
        $termSlug = trim($termSlug);
        $termTypes = TermType::definitions();
        $contentTypes = ContentType::definitions();

        if (!isset($termTypes[$typeKey])) {
            http_response_code(404);
            $this->render('front/term.twig', [
                'term' => null,
                'items' => [],
                'content_types' => $contentTypes,
                'term_type' => null,
                'heading' => 'Nenalezeno',
                'empty_message' => 'Zvolený typ termu neexistuje.',
            ]);
            return;
        }

        $term = R::findOne('term', ' slug = ? AND type = ? ', [$termSlug, $typeKey]);
        if (!$term) {
            http_response_code(404);
            $this->render('front/term.twig', [
                'term' => null,
                'items' => [],
                'content_types' => $contentTypes,
                'term_type' => $termTypes[$typeKey] ?? null,
                'heading' => 'Nenalezeno',
                'empty_message' => 'Vybraný term nebyl nalezen.',
            ]);
            return;
        }

        $content = $this->loadTermContent($term);

        $this->render('front/term.twig', [
            'term' => $term,
            'items' => $content,
            'content_types' => $contentTypes,
            'term_type' => $termTypes[$typeKey] ?? null,
            'heading' => $term->name,
            'empty_message' => 'Tento term zatím nemá žádný obsah.',
        ]);
    }

    private function loadTermContent($term): array
    {
        $conditions = [' ct.term_id = ? ', ' c.status = ? ', ' c.deleted_at IS NULL '];
        $params = [$term->id, 'published'];

        $allowedTypes = TermType::contentTypesFor($term->type);
        if (!empty($allowedTypes)) {
            $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
            $conditions[] = ' c.type IN (' . $placeholders . ') ';
            $params = array_merge($params, $allowedTypes);
        }

        $rows = R::getAll(
            'SELECT c.* FROM content c INNER JOIN content_term ct ON c.id = ct.content_id ' .
            'WHERE ' . implode(' AND ', $conditions) . ' ORDER BY c.created_at DESC',
            $params
        );

        $items = R::convertToBeans('content', $rows);

        return $this->attachAuthors($items);
    }
}
