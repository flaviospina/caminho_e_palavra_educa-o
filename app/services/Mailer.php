<?php
// app/services/Mailer.php
// Envio de e-mail em texto simples. MAIL_DRIVER=mail usa a função mail() do PHP
// (HostGator/HestiaCP); MAIL_DRIVER=log grava em storage/logs/mail.log (testes).
declare(strict_types=1);

namespace App\Services;

use App\Log;

final class Mailer
{
    public function __construct(private ?string $driver = null)
    {
        $this->driver ??= (string) env('MAIL_DRIVER', APP_ENV === 'test' ? 'log' : 'mail');
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public static function logFile(): string
    {
        return LOG_PATH . '/mail.log';
    }

    /** Envia (ou registra) a mensagem. Devolve true se foi aceita. */
    public function send(string $to, string $subject, string $body): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $fromEmail = (string) env('MAIL_FROM', 'nao-responda@cecapescs.com.br');
        $fromName = (string) env('MAIL_FROM_NAME', APP_NAME);
        if ($this->driver === 'log') {
            if (!is_dir(LOG_PATH)) {
                @mkdir(LOG_PATH, 0750, true);
            }
            $entry = sprintf("=== %s\nPara: %s\nDe: %s <%s>\nAssunto: %s\n\n%s\n\n", date('Y-m-d H:i:s'), $to, $fromName, $fromEmail, $subject, $body);
            return @file_put_contents(self::logFile(), $entry, FILE_APPEND | LOCK_EX) !== false;
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = implode("\r\n", [
            "From: {$encodedName} <{$fromEmail}>",
            "Reply-To: {$fromEmail}",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);
        $ok = @mail($to, $encodedSubject, wordwrap($body, 78, "\n", false), $headers, '-f' . $fromEmail);
        if (!$ok) {
            Log::error('Falha ao enviar e-mail', ['para' => $to, 'assunto' => $subject]);
        }
        return $ok;
    }
}
