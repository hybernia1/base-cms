<?php
namespace App\Controller\Install;

use App\Service\Mail;
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
        $user->password = password_hash($values['admin_pass'], PASSWORD_DEFAULT);
        $user->role = 'admin';
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
                `password` VARCHAR(255) NOT NULL,
                `role` VARCHAR(50) NOT NULL DEFAULT 'editor',
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
                `body` TEXT,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                UNIQUE KEY `slug_type` (`slug`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        R::exec(
            "CREATE TABLE IF NOT EXISTS `term` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(191) NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `description` TEXT,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                UNIQUE KEY `slug_type` (`slug`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
