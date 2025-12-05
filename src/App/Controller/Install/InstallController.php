<?php
namespace App\Controller\Install;

use App\Service\Mail;
use App\Service\EmailTemplateManager;
use App\Service\UserProfile;
use App\Service\Setting;
use App\Service\ThemeManager;
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
                'transport' => 'mail',
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
                `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `locked_until` DATETIME DEFAULT NULL,
                `last_login_at` DATETIME DEFAULT NULL,
                `last_login_ip` VARCHAR(45) DEFAULT NULL,
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
                `excerpt` TEXT,
                `body` TEXT,
                `thumbnail_id` INT UNSIGNED DEFAULT NULL,
                `thumbnail_alt` VARCHAR(255) DEFAULT '',
                `schema_type` VARCHAR(100) NOT NULL DEFAULT 'Article',
                `publish_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `metakey` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(191) NOT NULL,
                `key` VARCHAR(191) NOT NULL UNIQUE,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `meta` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `metakey_id` INT UNSIGNED NOT NULL,
                `target_type` VARCHAR(50) NOT NULL,
                `target_id` INT UNSIGNED NOT NULL,
                `value` TEXT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `meta_unique` (`metakey_id`, `target_type`, `target_id`),
                KEY `idx_target` (`target_type`, `target_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `navigationitem` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `label` VARCHAR(255) NOT NULL,
                `type` VARCHAR(50) NOT NULL DEFAULT 'custom',
                `url` VARCHAR(255) DEFAULT NULL,
                `target_id` INT UNSIGNED DEFAULT NULL,
                `target_key` VARCHAR(191) DEFAULT NULL,
                `parent_id` INT UNSIGNED DEFAULT NULL,
                `position` INT UNSIGNED NOT NULL DEFAULT 0,
                `open_in_new_tab` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_parent` (`parent_id`),
                KEY `idx_type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `emailtemplate` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `event` VARCHAR(191) NOT NULL UNIQUE,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
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
                `ip_address` VARCHAR(45) DEFAULT '',
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

        foreach (EmailTemplateManager::defaults() as $event => $template) {
            if (!R::findOne('emailtemplate', ' event = ? ', [$event])) {
                EmailTemplateManager::updateTemplate($event, $template);
            }
        }

        $defaultTheme = Setting::DEFAULTS['theme'] ?? ThemeManager::DEFAULT_THEME;
        R::exec(
            "INSERT IGNORE INTO setting (`key`, `value`, `created_at`, `updated_at`) VALUES ('theme', ?, NOW(), NOW())",
            [$defaultTheme]
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `passwordreset` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `token_hash` VARCHAR(255) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `used_at` DATETIME DEFAULT NULL,
                KEY `idx_user` (`user_id`),
                UNIQUE KEY `token_hash_unique` (`token_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `loginlog` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `ip_address` VARCHAR(45) NOT NULL,
                `created_at` DATETIME NOT NULL,
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (R::count('navigationitem') === 0) {
            $now = date('Y-m-d H:i:s');
            R::exec(
                "INSERT INTO navigationitem (label, type, url, target_id, target_key, parent_id, position, open_in_new_tab, created_at, updated_at)
                 VALUES
                 ('Domů', 'core_home', NULL, NULL, NULL, NULL, 0, 0, ?, ?),
                 ('Archiv', 'archive', NULL, NULL, 'post', NULL, 1, 0, ?, ?),
                 ('Vyhledávání', 'core_search', NULL, NULL, NULL, NULL, 2, 0, ?, ?),
                 ('Přihlášení', 'core_login', NULL, NULL, NULL, NULL, 3, 0, ?, ?),
                 ('Registrace', 'core_register', NULL, NULL, NULL, NULL, 4, 0, ?, ?)",
                [$now, $now, $now, $now, $now, $now, $now, $now, $now, $now]
            );
        }
    }
}
