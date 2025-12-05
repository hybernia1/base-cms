<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Meta;
use App\Service\Slugger;
use RedBeanPHP\R as R;

class MetaController extends BaseAdminController
{
    public function index(): void
    {
        Auth::requireRole('admin');

        $this->render('admin/meta/index.twig', [
            'current_menu' => 'meta',
            'keys' => Meta::allKeys(),
            'values' => ['name' => '', 'key' => ''],
            'errors' => [],
        ]);
    }

    public function create(): void
    {
        Auth::requireRole('admin');

        $data = $this->sanitize();
        $errors = $this->validate($data);

        if ($errors) {
            $this->render('admin/meta/index.twig', [
                'current_menu' => 'meta',
                'keys' => Meta::allKeys(),
                'values' => $data,
                'errors' => $errors,
            ]);
            return;
        }

        $key = R::dispense('metakey');
        $key->name = $data['name'];
        $key->key = $data['key'];
        $key->created_at = date('Y-m-d H:i:s');
        $key->updated_at = date('Y-m-d H:i:s');
        R::store($key);

        Flash::addSuccess('Meta klíč byl vytvořen.');
        header('Location: /admin/meta');
        exit;
    }

    public function delete($id): void
    {
        Auth::requireRole('admin');

        Meta::deleteKeyWithValues((int) $id);
        Flash::addSuccess('Meta klíč byl smazán.');
        header('Location: /admin/meta');
        exit;
    }

    private function sanitize(): array
    {
        $name = trim($_POST['name'] ?? '');
        $key = trim($_POST['key'] ?? '');

        if ($key === '' && $name !== '') {
            $key = $name;
        }

        return [
            'name' => $name,
            'key' => Slugger::slugify($key),
        ];
    }

    private function validate(array &$data): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors['name'] = 'Název je povinný.';
        }

        if ($data['key'] === '') {
            $errors['key'] = 'Vyplň klíč, používá se v šablonách.';
        } elseif (Meta::keyExists($data['key'])) {
            $errors['key'] = 'Meta klíč se stejným klíčem už existuje.';
        }

        return $errors;
    }
}
