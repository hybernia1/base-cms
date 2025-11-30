<?php
namespace App\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mail
{
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $config = $GLOBALS['app']['config']['mail'] ?? null;
        if (!$config) {
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $config['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['username'];
            $mail->Password   = $config['password'];
            $mail->SMTPSecure = $config['encryption'] ?: PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config['port'] ?? 587;

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
