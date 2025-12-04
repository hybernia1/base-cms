<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Flash;
use App\Service\Navigation;
use RedBeanPHP\R as R;

class NavigationController extends BaseAdminController
{
    public function index(): void
    {
        Auth::requireRole(['admin']);

        $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
        $values = $this->defaultValues();

        if ($editId) {
            $item = R::findOne('navigationitem', ' id = ? ', [$editId]);
            if ($item) {
                $values = $this->valuesFromBean($item);
            }
        }

        $this->renderPage($values, [], $editId);
    }

    public function create(): void
    {
        Auth::requireRole(['admin']);

        [$data, $errors] = $this->sanitizeInput();

        if ($errors) {
            $this->renderPage($data, $errors);
            return;
        }

        $bean = R::dispense('navigationitem');
        $this->fillBean($bean, $data);
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);

        Flash::addSuccess('Položka byla vytvořena.');
        header('Location: /admin/navigation');
        exit;
    }

    public function update($id): void
    {
        Auth::requireRole(['admin']);
        $item = R::findOne('navigationitem', ' id = ? ', [$id]);

        if (!$item) {
            Flash::addError('Položka nebyla nalezena.');
            header('Location: /admin/navigation');
            exit;
        }

        [$data, $errors] = $this->sanitizeInput((int) $item->id);

        if ($errors) {
            $this->renderPage($data, $errors, (int) $item->id);
            return;
        }

        $this->fillBean($item, $data);
        $item->updated_at = date('Y-m-d H:i:s');
        R::store($item);

        Flash::addSuccess('Položka byla aktualizována.');
        header('Location: /admin/navigation');
        exit;
    }

    public function delete($id): void
    {
        Auth::requireRole(['admin']);
        $item = R::findOne('navigationitem', ' id = ? ', [$id]);

        if (!$item) {
            Flash::addError('Položka nebyla nalezena.');
            header('Location: /admin/navigation');
            exit;
        }

        R::trash($item);
        R::exec('UPDATE navigationitem SET parent_id = NULL WHERE parent_id = ?', [$id]);

        Flash::addSuccess('Položka byla smazána.');
        header('Location: /admin/navigation');
        exit;
    }

    private function sanitizeInput(?int $editingId = null): array
    {
        $data = [
            'label' => trim($_POST['label'] ?? ''),
            'type' => trim($_POST['type'] ?? Navigation::TYPE_CUSTOM),
            'url' => trim($_POST['url'] ?? ''),
            'content_id' => (int) ($_POST['content_id'] ?? 0),
            'term_id' => (int) ($_POST['term_id'] ?? 0),
            'target_id' => null,
            'target_key' => trim($_POST['target_key'] ?? ''),
            'parent_id' => (int) ($_POST['parent_id'] ?? 0) ?: null,
            'position' => max(0, (int) ($_POST['position'] ?? 0)),
            'open_in_new_tab' => isset($_POST['open_in_new_tab']) && $_POST['open_in_new_tab'] === '1',
        ];

        $errors = [];
        if ($data['label'] === '') {
            $errors['label'] = 'Název je povinný.';
        }

        if (!in_array($data['type'], Navigation::allowedTypes(), true)) {
            $errors['type'] = 'Neplatný typ odkazu.';
        }

        if ($editingId !== null && $data['parent_id'] === $editingId) {
            $errors['parent_id'] = 'Položka nemůže být sama sobě rodičem.';
        }

        switch ($data['type']) {
            case Navigation::TYPE_CUSTOM:
                if ($data['url'] === '') {
                    $errors['url'] = 'Zadejte cílovou URL.';
                }
                $data['target_id'] = null;
                $data['target_key'] = null;
                break;

            case Navigation::TYPE_CONTENT:
                $data['target_id'] = $data['content_id'];
                if ($data['target_id'] <= 0) {
                    $errors['target_id'] = 'Vyberte obsah.';
                }
                $data['url'] = null;
                $data['target_key'] = null;
                break;

            case Navigation::TYPE_TERM:
                $data['target_id'] = $data['term_id'];
                if ($data['target_id'] <= 0) {
                    $errors['target_id'] = 'Vyberte term.';
                }
                $data['url'] = null;
                $data['target_key'] = null;
                break;

            case Navigation::TYPE_ARCHIVE:
                if ($data['target_key'] === '' || !ContentType::exists($data['target_key'])) {
                    $errors['target_key'] = 'Vyberte typ obsahu.';
                }
                $data['url'] = null;
                $data['target_id'] = null;
                break;

            default:
                $data['url'] = null;
                $data['target_id'] = null;
                $data['target_key'] = null;
                break;
        }

        return [$data, $errors];
    }

    private function renderPage(array $values, array $errors = [], ?int $editId = null): void
    {
        $this->render('admin/navigation/index.twig', [
            'current_menu' => 'navigation',
            'items' => Navigation::tree(false),
            'parent_options' => Navigation::flatList(),
            'content_options' => R::findAll('content', ' status = ? AND deleted_at IS NULL ORDER BY created_at DESC ', ['published']),
            'term_options' => R::findAll('term', ' ORDER BY name ASC '),
            'content_types' => ContentType::definitions(),
            'types' => Navigation::typeOptions(),
            'values' => $values,
            'errors' => $errors,
            'edit_id' => $editId,
        ]);
    }

    private function fillBean($bean, array $data): void
    {
        $bean->label = $data['label'];
        $bean->type = $data['type'];
        $bean->url = $data['url'];
        $bean->target_id = $data['target_id'];
        $bean->target_key = $data['target_key'];
        $bean->parent_id = $data['parent_id'];
        $bean->position = $data['position'];
        $bean->open_in_new_tab = $data['open_in_new_tab'] ? 1 : 0;
    }

    private function defaultValues(): array
    {
        return [
            'label' => '',
            'type' => Navigation::TYPE_CUSTOM,
            'url' => '',
            'content_id' => null,
            'term_id' => null,
            'target_id' => null,
            'target_key' => null,
            'parent_id' => null,
            'position' => 0,
            'open_in_new_tab' => false,
        ];
    }

    private function valuesFromBean($bean): array
    {
        return [
            'label' => $bean->label ?? '',
            'type' => $bean->type ?? Navigation::TYPE_CUSTOM,
            'url' => $bean->url ?? '',
            'content_id' => $bean->target_id ?? null,
            'term_id' => $bean->target_id ?? null,
            'target_id' => $bean->target_id ?? null,
            'target_key' => $bean->target_key ?? null,
            'parent_id' => $bean->parent_id ?? null,
            'position' => (int) ($bean->position ?? 0),
            'open_in_new_tab' => (int) ($bean->open_in_new_tab ?? 0) === 1,
        ];
    }
}
