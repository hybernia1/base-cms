<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\EmailTemplateManager;
use App\Service\Flash;
use RedBeanPHP\R as R;

class UserController extends BaseAdminController
{
    private const ROLES = [
        'admin'  => 'Administrátor',
        'editor' => 'Editor',
        'user'   => 'Uživatel',
    ];

    public function index()
    {
        Auth::requireRole('admin');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($_GET['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;
        $searchQuery = trim($_GET['q'] ?? '');

        $query = ' 1 ';
        $params = [];

        if ($searchQuery !== '') {
            $query .= ' AND (email LIKE ? OR role LIKE ?) ';
            $params[] = '%' . $searchQuery . '%';
            $params[] = '%' . $searchQuery . '%';
        }

        $total = R::count('user', $query, $params);
        $users = R::findAll(
            'user',
            $query . ' ORDER BY email ASC LIMIT ? OFFSET ? ',
            array_merge($params, [$perPage, $offset])
        );

        $pages = (int) ceil($total / $perPage);

        $this->render('admin/users/index.twig', [
            'users' => $users,
            'roles' => self::ROLES,
            'current_menu' => 'users',
            'search_query' => $searchQuery,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
                'total' => $total,
            ],
            'filters' => [
                'q' => $searchQuery,
                'per_page' => $perPage !== 10 ? $perPage : null,
            ],
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
        $this->ensureUserColumns();

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
        $user->is_banned = 0;
        $user->ban_reason = '';
        R::store($user);

        Flash::addSuccess('Uživatel byl vytvořen.');
        header('Location: /admin/users');
        exit;
    }

    public function editForm($id)
    {
        Auth::requireRole('admin');
        $this->ensureUserColumns();

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
                'is_banned' => (int) ($user->is_banned ?? 0),
                'ban_reason' => $user->ban_reason ?? '',
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
        $this->ensureUserColumns();

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
                'values' => array_merge($data, [
                    'is_banned' => (int) ($user->is_banned ?? 0),
                    'ban_reason' => $user->ban_reason ?? '',
                ]),
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

    public function ban($id)
    {
        Auth::requireRole('admin');
        $this->ensureUserColumns();

        $user = $this->findUser($id);
        if (!$user) {
            Flash::addError('Uživatel nenalezen.');
            header('Location: /admin/users');
            exit;
        }

        $currentUser = Auth::user();
        if ($currentUser && (int) $currentUser->id === (int) $user->id) {
            Flash::addError('Nemůžeš zablokovat sám sebe.');
            header('Location: /admin/users');
            exit;
        }

        $reason = trim($_POST['reason'] ?? '');

        $user->is_banned = 1;
        $user->banned_at = date('Y-m-d H:i:s');
        $user->ban_reason = $reason;
        R::store($user);

        EmailTemplateManager::send('user_banned', $user->email, [
            'email' => $user->email,
            'reason' => $reason !== '' ? $reason : 'neuvedeno',
        ]);

        Flash::addSuccess('Uživatel byl zablokován.');
        header('Location: /admin/users');
        exit;
    }

    public function unban($id)
    {
        Auth::requireRole('admin');

        $user = $this->findUser($id);
        if (!$user) {
            Flash::addError('Uživatel nenalezen.');
            header('Location: /admin/users');
            exit;
        }

        $user->is_banned = 0;
        $user->banned_at = null;
        $user->ban_reason = null;
        R::store($user);

        Flash::addSuccess('Blokace byla zrušena.');
        header('Location: /admin/users');
        exit;
    }

    public function delete($id)
    {
        Auth::requireRole('admin');

        $user = $this->findUser($id);
        if (!$user) {
            Flash::addError('Uživatel nenalezen.');
            if ($this->wantsJson()) {
                $this->json(['success' => false, 'message' => 'Uživatel nenalezen.'], 404);
            }
            header('Location: /admin/users');
            exit;
        }

        $currentUser = Auth::user();
        if ($currentUser && (int) $currentUser->id === (int) $user->id) {
            Flash::addError('Nemůžeš smazat sám sebe.');
            if ($this->wantsJson()) {
                $this->json(['success' => false, 'message' => 'Nemůžeš smazat sám sebe.'], 400);
            }
            header('Location: /admin/users');
            exit;
        }

        R::trash($user);
        Flash::addSuccess('Uživatel byl smazán.');
        if ($this->wantsJson()) {
            $this->json(['success' => true, 'message' => 'Uživatel byl smazán.']);
        }
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

    private function ensureUserColumns(): void
    {
        $columns = R::inspect('user');

        if (!isset($columns['is_banned'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `is_banned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`");
        }

        if (!isset($columns['ban_reason'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `ban_reason` TEXT DEFAULT NULL AFTER `is_banned`");
        }

        if (!isset($columns['banned_at'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `banned_at` DATETIME DEFAULT NULL AFTER `ban_reason`");
        }
    }
}
