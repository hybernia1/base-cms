<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use RedBeanPHP\R as R;

class UserController extends BaseAdminController
{
    private const ROLES = [
        'admin'  => 'Administrátor',
        'editor' => 'Editor',
    ];

    public function index()
    {
        Auth::requireRole('admin');

        $users = R::findAll('user', ' ORDER BY email ASC ');

        $this->render('admin/users/index.twig', [
            'users' => $users,
            'roles' => self::ROLES,
            'current_menu' => 'users',
        ]);
    }

    public function createForm()
    {
        Auth::requireRole('admin');

        $this->render('admin/users/form.twig', [
            'roles' => self::ROLES,
            'current_menu' => 'users',
            'form_action' => '/admin/users/create',
            'heading' => 'Nový uživatel',
            'errors' => [],
            'values' => [],
        ]);
    }

    public function create()
    {
        Auth::requireRole('admin');

        $data = $this->sanitizeInput();
        $errors = $this->validateUser($data);

        if ($this->emailExists($data['email'])) {
            $errors['email'] = 'Uživatel s tímto e-mailem již existuje.';
        }

        if ($errors) {
            $this->render('admin/users/form.twig', [
                'roles' => self::ROLES,
                'errors' => $errors,
                'values' => $data,
                'current_menu' => 'users',
                'form_action' => '/admin/users/create',
                'heading' => 'Nový uživatel',
            ]);
            return;
        }

        $user = R::dispense('user');
        $user->email = $data['email'];
        $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        $user->role = $data['role'];
        R::store($user);

        Flash::addSuccess('Uživatel byl vytvořen.');
        header('Location: /admin/users');
        exit;
    }

    public function editForm($id)
    {
        Auth::requireRole('admin');

        $user = $this->findUser($id);
        if (!$user) {
            Flash::addError('Uživatel nenalezen.');
            header('Location: /admin/users');
            exit;
        }

        $this->render('admin/users/form.twig', [
            'roles' => self::ROLES,
            'values' => [
                'email' => $user->email,
                'role'  => $user->role,
            ],
            'current_menu' => 'users',
            'form_action' => "/admin/users/{$user->id}/edit",
            'heading' => 'Upravit uživatele',
            'user_id' => $user->id,
            'errors' => [],
        ]);
    }

    public function update($id)
    {
        Auth::requireRole('admin');

        $user = $this->findUser($id);
        if (!$user) {
            Flash::addError('Uživatel nenalezen.');
            header('Location: /admin/users');
            exit;
        }

        $data = $this->sanitizeInput();
        $errors = $this->validateUser($data, $isUpdate = true);

        if ($this->emailExists($data['email'], (int) $user->id)) {
            $errors['email'] = 'Uživatel s tímto e-mailem již existuje.';
        }

        if ($errors) {
            $this->render('admin/users/form.twig', [
                'roles' => self::ROLES,
                'errors' => $errors,
                'values' => $data,
                'current_menu' => 'users',
                'form_action' => "/admin/users/{$user->id}/edit",
                'heading' => 'Upravit uživatele',
                'user_id' => $user->id,
            ]);
            return;
        }

        $user->email = $data['email'];
        $user->role = $data['role'];
        if ($data['password']) {
            $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        R::store($user);

        Flash::addSuccess('Uživatel byl upraven.');
        header('Location: /admin/users');
        exit;
    }

    public function delete($id)
    {
        Auth::requireRole('admin');

        $user = $this->findUser($id);
        if (!$user) {
            Flash::addError('Uživatel nenalezen.');
            header('Location: /admin/users');
            exit;
        }

        $currentUser = Auth::user();
        if ($currentUser && (int) $currentUser->id === (int) $user->id) {
            Flash::addError('Nemůžeš smazat sám sebe.');
            header('Location: /admin/users');
            exit;
        }

        R::trash($user);
        Flash::addSuccess('Uživatel byl smazán.');
        header('Location: /admin/users');
        exit;
    }

    private function sanitizeInput(): array
    {
        return [
            'email' => trim($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'role' => trim($_POST['role'] ?? ''),
        ];
    }

    private function validateUser(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Zadej platný e-mail.';
        }

        if (!$isUpdate || $data['password'] !== '') {
            if (strlen($data['password']) < 6) {
                $errors['password'] = 'Heslo musí mít alespoň 6 znaků.';
            }
        }

        if (!array_key_exists($data['role'], self::ROLES)) {
            $errors['role'] = 'Vyber platnou roli.';
        }

        return $errors;
    }

    private function emailExists(string $email, int $ignoreId = 0): bool
    {
        $query = ' email = ? ';
        $params = [$email];

        if ($ignoreId > 0) {
            $query .= ' AND id != ? ';
            $params[] = $ignoreId;
        }

        return (bool) R::findOne('user', $query, $params);
    }

    private function findUser($id)
    {
        $user = R::load('user', (int) $id);
        return $user && $user->id ? $user : null;
    }
}
