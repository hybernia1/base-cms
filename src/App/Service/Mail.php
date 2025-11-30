<?php
namespace App\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Service\Setting;

class Mail
{
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $configFile = $GLOBALS['app']['config']['mail'] ?? [];
        $config = [
            'host' => Setting::get('smtp_host', $configFile['host'] ?? ''),
            'port' => (int) Setting::get('smtp_port', $configFile['port'] ?? 587),
            'username' => Setting::get('smtp_username', $configFile['username'] ?? ''),
            'password' => Setting::get('smtp_password', $configFile['password'] ?? ''),
            'from_email' => Setting::get('smtp_from_email', $configFile['from_email'] ?? ''),
            'from_name' => Setting::get('smtp_from_name', $configFile['from_name'] ?? ''),
            'encryption' => Setting::get('smtp_encryption', $configFile['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS),
        ];

        if (trim((string) $config['host']) === '' || trim((string) $config['from_email']) === '') {
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $config['host'];
            $mail->SMTPAuth   = !empty($config['username']) || !empty($config['password']);
            $mail->Username   = $config['username'];
            $mail->Password   = $config['password'];
            $mail->SMTPSecure = $config['encryption'] ?: PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config['port'] ?: 587;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';

            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody ?: strip_tags($htmlBody);

            $mail->send();
            return true;
        } catch (Exception $e) {
            // tady můžeš logovat
            return false;
        }
    }
}
