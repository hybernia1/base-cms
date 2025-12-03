<?php
namespace App\Controller\Front;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Flash;
use App\Service\UserProfile;
use RedBeanPHP\R as R;

class UserController extends BaseFrontController
{
    public function profile(): void
    {
        $currentUser = Auth::user();
        if (!$currentUser) {
            Flash::addError('Pro zobrazení profilu se prosím přihlašte.');
            header('Location: /login');
            exit;
        }

        $this->renderProfile($currentUser, true);
    }

    public function show($id): void
    {
        $user = $this->loadUser((int) $id);
        if (!$user) {
            $this->renderNotFound([
                'message' => 'Profil nebyl nalezen nebo je nedostupný.',
            ]);
            return;
        }

        $currentUser = Auth::user();
        $isOwner = $currentUser && (int) $currentUser->id === (int) $user->id;

        if (!$isOwner && (int) ($user->is_profile_public ?? 1) !== 1) {
            http_response_code(403);
            $this->render('front/user/profile.twig', [
                'user' => $user,
                'is_owner' => $isOwner,
                'is_public' => false,
                'comments' => [],
            ]);
            return;
        }

        $this->renderProfile($user, $isOwner);
    }

    public function editForm(): void
    {
        $user = Auth::user();
        if (!$user) {
            Flash::addError('Pro úpravu profilu se přihlašte.');
            header('Location: /login');
            exit;
        }

        $this->render('front/user/edit.twig', [
            'values' => [
                'email' => $user->email,
                'nickname' => $user->nickname,
                'is_profile_public' => (int) ($user->is_profile_public ?? 1),
            ],
            'errors' => [],
        ]);
    }

    public function update(): void
    {
        $user = Auth::user();
        if (!$user) {
            Flash::addError('Pro úpravu profilu se přihlašte.');
            header('Location: /login');
            exit;
        }

        $data = $this->sanitizeProfile();
        $errors = $this->validateProfile($data);

        if ($errors) {
            $this->render('front/user/edit.twig', [
                'values' => array_merge($data, ['email' => $user->email]),
                'errors' => $errors,
            ]);
            return;
        }

        $user->nickname = $data['nickname'];
        $user->is_profile_public = $data['is_profile_public'];
        $user->updated_at = date('Y-m-d H:i:s');

        if ($data['password'] !== '') {
            $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        R::store($user);

        Flash::addSuccess('Profil byl aktualizován.');
        header('Location: /profile');
        exit;
    }

    private function renderProfile($user, bool $isOwner): void
    {
        $adminBar = [];
        if (Auth::hasRole('admin')) {
            $adminBar = [
                'edit_url' => '/admin/users/' . $user->id . '/edit',
                'current_title' => $user->nickname ?: $user->email,
            ];
        }

        $this->render('front/user/profile.twig', [
            'user' => $user,
            'is_owner' => $isOwner,
            'is_public' => (int) ($user->is_profile_public ?? 1) === 1,
            'is_banned' => (int) ($user->is_banned ?? 0) === 1,
            'ban_reason' => $user->ban_reason ?? null,
            'comments' => $this->findUserComments((int) $user->id, $isOwner),
            'admin_bar' => $adminBar,
        ]);
    }

    private function findUserComments(int $userId, bool $includePending): array
    {
        $query = 'SELECT c.id, c.body, c.created_at, c.status, c.content_id, cnt.title, cnt.slug, cnt.type '
            . 'FROM comment c '
            . 'LEFT JOIN content cnt ON c.content_id = cnt.id '
            . 'WHERE c.user_id = ? ';
        $params = [$userId];

        if (!$includePending) {
            $query .= 'AND c.status = ? ';
            $params[] = 'approved';
        }

        $query .= 'ORDER BY c.created_at DESC LIMIT 20';

        $rows = R::getAll($query, $params);

        return array_map(function ($row) {
            $contentUrl = null;
            if (!empty($row['slug']) && !empty($row['type'])) {
                $contentUrl = '/' . ContentType::slug($row['type']) . '/' . $row['slug'];
            }

            return [
                'id' => (int) $row['id'],
                'body' => $row['body'],
                'created_at' => $row['created_at'],
                'status' => $row['status'],
                'content_title' => $row['title'] ?? 'Neznámý obsah',
                'content_url' => $contentUrl,
            ];
        }, $rows);
    }

    private function loadUser(int $id)
    {
        $user = R::load('user', $id);
        return $user && $user->id ? $user : null;
    }

    private function sanitizeProfile(): array
    {
        return [
            'nickname' => trim($_POST['nickname'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password_confirm'] ?? '',
            'is_profile_public' => isset($_POST['is_profile_public']) ? 1 : 0,
        ];
    }

    private function validateProfile(array $data): array
    {
        $errors = [];

        if ($data['nickname'] === '') {
            $errors['nickname'] = 'Přezdívka je povinná.';
        }

        if ($data['password'] !== '') {
            if (strlen($data['password']) < 6) {
                $errors['password'] = 'Heslo musí mít alespoň 6 znaků.';
            }

            if ($data['password'] !== $data['password_confirm']) {
                $errors['password_confirm'] = 'Hesla se neshodují.';
            }
        }

        return $errors;
    }
}
