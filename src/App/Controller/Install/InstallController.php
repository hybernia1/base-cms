<?php
namespace App\Controller\Install;

use App\Service\Mail;
use App\Service\EmailTemplateManager;
use App\Service\UserProfile;
use RedBeanPHP\R as R;

class InstallController
{
    private $twig;
    private $configDir;

    public function __construct()
    {
        $this->twig = $GLOBALS['app']['twig'];
        $this->configDir = __DIR__ . '/../../Config';
    }

    public function form()
    {
        echo $this->twig->render('install/form.twig', [
            'error'  => null,
            'values' => [],
        ]);
    }

    public function handle()
    {
        $values = [
            'db_host' => trim($_POST['db_host'] ?? ''),
            'db_name' => trim($_POST['db_name'] ?? ''),
            'db_user' => trim($_POST['db_user'] ?? ''),
            'db_pass' => trim($_POST['db_pass'] ?? ''),
            'admin_email' => trim($_POST['admin_email'] ?? ''),
            'admin_pass'  => $_POST['admin_pass'] ?? '',
        ];

        // basic validation
        $errors = [];
        foreach (['db_host','db_name','db_user','admin_email','admin_pass'] as $field) {
            if ($values[$field] === '') {
                $errors[$field] = 'Toto pole je povinné.';
            }
        }

        if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'Neplatný e-mail.';
        }

        if ($errors) {
            echo $this->twig->render('install/form.twig', [
                'errors' => $errors,
                'values' => $values,
            ]);
            return;
        }

        $dsn = "mysql:host={$values['db_host']};dbname={$values['db_name']};charset=utf8mb4";

        // test DB
        R::setup($dsn, $values['db_user'], $values['db_pass']);
        if (!R::testConnection()) {
            echo $this->twig->render('install/form.twig', [
                'error'  => 'Nelze se připojit k databázi.',
                'values' => $values,
            ]);
            return;
        }

        $this->createSchema();

        // vytvořit config.php
        $config = [
            'db' => [
                'dsn'  => $dsn,
                'user' => $values['db_user'],
                'pass' => $values['db_pass'],
            ],
            'mail' => [
                'host' => '',
                'port' => 587,
                'username' => '',
                'password' => '',
                'from_email' => $values['admin_email'],
                'from_name'  => 'Moje CMS',
                'encryption' => 'tls',
            ],
            'env' => 'prod',
            'installed' => true,
        ];

        $configPhp = "<?php\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($this->configDir . '/config.php', $configPhp);

        $GLOBALS['app']['config'] = $config;

        // admin user
        $user = R::dispense('user');
        $user->email = $values['admin_email'];
        UserProfile::ensureColumns();

        $user->password = password_hash($values['admin_pass'], PASSWORD_DEFAULT);
        $user->role = 'admin';
        $user->nickname = UserProfile::generateNickname($values['admin_email']);
        $user->is_profile_public = 1;
        $user->created_at = date('Y-m-d H:i:s');
        $user->updated_at = date('Y-m-d H:i:s');
        R::store($user);

        Mail::send(
            $values['admin_email'],
            'Instalace byla dokončena',
            '<p>CMS bylo úspěšně nainstalováno.</p>'
        );

        // auto login
        $_SESSION['user_id'] = $user->id;

        header('Location: /admin');
        exit;
    }

    private function createSchema(): void
    {
        R::exec(
            "CREATE TABLE IF NOT EXISTS `user` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(191) NOT NULL UNIQUE,
                `nickname` VARCHAR(191) NOT NULL DEFAULT '',
                `password` VARCHAR(255) NOT NULL,
                `role` VARCHAR(50) NOT NULL DEFAULT 'editor',
                `is_profile_public` TINYINT(1) NOT NULL DEFAULT 1,
                `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
                `ban_reason` TEXT DEFAULT NULL,
                `banned_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `content` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(191) NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `author_id` INT UNSIGNED DEFAULT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'published',
                `allow_comments` TINYINT(1) NOT NULL DEFAULT 1,
                `body` TEXT,
                `thumbnail_id` INT UNSIGNED DEFAULT NULL,
                `thumbnail_alt` VARCHAR(255) DEFAULT '',
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `deleted_at` DATETIME DEFAULT NULL,
                UNIQUE KEY `slug_type` (`slug`, `type`),
                KEY `idx_author` (`author_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `term` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(191) NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `description` TEXT,
                `content_types` TEXT,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                UNIQUE KEY `slug_type` (`slug`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `content_term` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `content_id` INT UNSIGNED NOT NULL,
                `term_id` INT UNSIGNED NOT NULL,
                UNIQUE KEY `content_term_unique` (`content_id`, `term_id`),
                KEY `idx_term` (`term_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `media` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `uploaded_by` INT UNSIGNED DEFAULT NULL,
                `path` VARCHAR(255) NOT NULL,
                `filename` VARCHAR(255) NOT NULL,
                `webp_filename` VARCHAR(255) DEFAULT NULL,
                `mime_type` VARCHAR(191) NOT NULL,
                `size` INT UNSIGNED DEFAULT 0,
                `is_image` TINYINT(1) NOT NULL DEFAULT 0,
                `original_name` VARCHAR(255) DEFAULT '',
                `alt` VARCHAR(255) DEFAULT '',
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_uploaded_by` (`uploaded_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `content_media` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `content_id` INT UNSIGNED NOT NULL,
                `media_id` INT UNSIGNED NOT NULL,
                `relation` VARCHAR(50) NOT NULL DEFAULT 'body',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `content_media_unique` (`content_id`, `media_id`, `relation`),
                KEY `idx_media` (`media_id`),
                KEY `idx_relation` (`relation`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `setting` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(191) NOT NULL UNIQUE,
                `value` TEXT,
                `updated_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `emailtemplate` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `event` VARCHAR(191) NOT NULL UNIQUE,
                `subject` VARCHAR(255) NOT NULL,
                `body_html` TEXT NOT NULL,
                `body_text` TEXT NOT NULL,
                `updated_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `comment` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `content_id` INT UNSIGNED NOT NULL,
                `parent_id` INT UNSIGNED DEFAULT NULL,
                `user_id` INT UNSIGNED DEFAULT NULL,
                `author_name` VARCHAR(191) DEFAULT '',
                `author_email` VARCHAR(191) DEFAULT '',
                `body` TEXT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `depth` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `deleted_at` DATETIME DEFAULT NULL,
                KEY `idx_content` (`content_id`),
                KEY `idx_parent` (`parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $contentColumns = R::inspect('content');
        if (!isset($contentColumns['thumbnail_id'])) {
            R::exec("ALTER TABLE `content` ADD COLUMN `thumbnail_id` INT UNSIGNED DEFAULT NULL AFTER `body`");
        }

        if (!isset($contentColumns['thumbnail_alt'])) {
            R::exec("ALTER TABLE `content` ADD COLUMN `thumbnail_alt` VARCHAR(255) DEFAULT '' AFTER `thumbnail_id`");
        }

        if (!isset($contentColumns['status'])) {
            R::exec("ALTER TABLE `content` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'published' AFTER `type`");
        }

        if (!isset($contentColumns['allow_comments'])) {
            R::exec("ALTER TABLE `content` ADD COLUMN `allow_comments` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`");
        }

        if (!isset($contentColumns['author_id'])) {
            R::exec("ALTER TABLE `content` ADD COLUMN `author_id` INT UNSIGNED DEFAULT NULL AFTER `type`");
            R::exec("CREATE INDEX idx_author ON content (author_id)");
        }

        $contentMediaColumns = R::inspect('content_media');

        if (!isset($contentMediaColumns['relation'])) {
            R::exec("ALTER TABLE `content_media` ADD COLUMN `relation` VARCHAR(50) NOT NULL DEFAULT 'body' AFTER `media_id`");
        }

        if (!isset($contentMediaColumns['created_at'])) {
            R::exec("ALTER TABLE `content_media` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `relation`");
        }

        R::exec(
            "INSERT IGNORE INTO content_media (content_id, media_id, relation, created_at)
             SELECT id, thumbnail_id, 'thumbnail', NOW()
             FROM content
             WHERE thumbnail_id IS NOT NULL"
        );

        $termColumns = R::inspect('term');
        if (!isset($termColumns['content_types'])) {
            R::exec("ALTER TABLE `term` ADD COLUMN `content_types` TEXT AFTER `description`");
        }

        $userColumns = R::inspect('user');
        if (!isset($userColumns['nickname'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `nickname` VARCHAR(191) NOT NULL DEFAULT '' AFTER `email`");
        }
        if (!isset($userColumns['is_banned'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `is_banned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`");
        }
        if (!isset($userColumns['is_profile_public'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `is_profile_public` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`");
        }
        if (!isset($userColumns['ban_reason'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `ban_reason` TEXT DEFAULT NULL AFTER `is_banned`");
        }
        if (!isset($userColumns['banned_at'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `banned_at` DATETIME DEFAULT NULL AFTER `ban_reason`");
        }

        $mediaColumns = R::inspect('media');
        if (!isset($mediaColumns['uploaded_by'])) {
            R::exec("ALTER TABLE `media` ADD COLUMN `uploaded_by` INT UNSIGNED DEFAULT NULL AFTER `id`");
            R::exec("CREATE INDEX idx_uploaded_by ON media (uploaded_by)");
        }

        foreach (EmailTemplateManager::defaults() as $event => $template) {
            if (!R::findOne('emailtemplate', ' event = ? ', [$event])) {
                EmailTemplateManager::updateTemplate($event, $template);
            }
        }
    }
}
