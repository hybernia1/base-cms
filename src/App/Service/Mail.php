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
            'transport' => Setting::get('mail_transport', $configFile['transport'] ?? 'mail'),
            'host' => Setting::get('smtp_host', $configFile['host'] ?? ''),
            'port' => (int) Setting::get('smtp_port', $configFile['port'] ?? 587),
            'username' => Setting::get('smtp_username', $configFile['username'] ?? ''),
            'password' => Setting::get('smtp_password', $configFile['password'] ?? ''),
            'from_email' => Setting::get('smtp_from_email', $configFile['from_email'] ?? ''),
            'from_name' => Setting::get('smtp_from_name', $configFile['from_name'] ?? ''),
            'encryption' => Setting::get('smtp_encryption', $configFile['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS),
        ];

        $transport = $config['transport'] === 'smtp' ? 'smtp' : 'mail';
        $hasSmtp = trim((string) $config['host']) !== '';
        $fromEmail = trim((string) $config['from_email']);

        if ($transport === 'mail') {
            if ($fromEmail === '') {
                return false;
            }

            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
            ];

            $fromHeader = $config['from_name'] !== ''
                ? sprintf('%s <%s>', $config['from_name'], $fromEmail)
                : $fromEmail;

            $headers[] = 'From: ' . $fromHeader;

            return mail($to, $subject, $htmlBody, implode("\r\n", $headers));
        }

        if ($transport !== 'smtp' || !$hasSmtp || $fromEmail === '') {
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
