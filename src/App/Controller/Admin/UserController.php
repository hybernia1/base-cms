<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\EmailTemplateManager;
use App\Service\Flash;
use App\Service\UserProfile;
use RedBeanPHP\R as R;

class UserController extends AjaxController
{
    private const ROLES = [
        'admin'  => 'Administrátor',
        'editor' => 'Editor',
        'user'   => 'Uživatel',
    ];

    public function index()
    {
        Auth::requireRole('admin');

        $total = R::count('user');
        $pagination = $this->buildPagination((int) $total, 15);

        $users = R::findAll(
            'user',
            ' ORDER BY email ASC LIMIT ? OFFSET ? ',
            [$pagination['per_page'], $pagination['offset']]
        );

        if ($this->respondAjax('admin/users/_list.twig', $this->prepareUsersAjaxPayload($users, [
            'roles' => self::ROLES,
            'pagination' => $pagination,
        ]), $pagination['current_url'])) {
            return;
        }

        $this->render('admin/users/index.twig', [
            'users' => $users,
            'roles' => self::ROLES,
            'current_menu' => 'users',
            'pagination' => $pagination,
        ]);
    }

    private function prepareUsersAjaxPayload(array $users, array $context): array
    {
        $serializedUsers = [];
        foreach ($users as $user) {
            $serializedUsers[] = [
                'id' => (int) $user->id,
                'email' => $user->email,
                'nickname' => $user->nickname,
                'role' => $user->role,
                'is_banned' => (bool) $user->is_banned,
                'ban_reason' => $user->ban_reason,
            ];
        }

        $context['users'] = $serializedUsers;

        return $context;
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
            'values' => [
                'nickname' => '',
            ],
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
        $user->nickname = $data['nickname'] !== '' ? $data['nickname'] : UserProfile::generateNickname($data['email']);
        $user->is_profile_public = 1;
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
                'nickname' => $user->nickname,
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
        $user->nickname = $data['nickname'] !== ''
            ? $data['nickname']
            : ($user->nickname !== '' && $user->nickname !== null
                ? $user->nickname
                : UserProfile::generateNickname($user->email));
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
            if ($this->wantsJson()) {
                $this->jsonError('Uživatel nenalezen.', 404);
            }

            Flash::addError('Uživatel nenalezen.');
            header('Location: /admin/users');
            exit;
        }

        $currentUser = Auth::user();
        if ($currentUser && (int) $currentUser->id === (int) $user->id) {
            if ($this->wantsJson()) {
                $this->jsonError('Nemůžeš smazat sám sebe.', 400);
            }

            Flash::addError('Nemůžeš smazat sám sebe.');
            header('Location: /admin/users');
            exit;
        }

        R::trash($user);

        if ($this->wantsJson()) {
            $this->respondAjaxMessage('Uživatel byl smazán.', ['success' => true]);
        }

        Flash::addSuccess('Uživatel byl smazán.');
        header('Location: ' . $this->redirectToList($_POST['redirect'] ?? null));
        exit;
    }

    private function redirectToList(?string $redirect): string
    {
        if ($redirect) {
            $parsed = parse_url($redirect);
            $path = $parsed['path'] ?? '';
            if (strpos($path, '/admin/users') === 0) {
                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                return $path . $query;
            }
        }

        return '/admin/users';
    }

    private function sanitizeInput(): array
    {
        return [
            'email' => trim($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'role' => trim($_POST['role'] ?? ''),
            'nickname' => trim($_POST['nickname'] ?? ''),
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

        UserProfile::ensureColumns();

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
