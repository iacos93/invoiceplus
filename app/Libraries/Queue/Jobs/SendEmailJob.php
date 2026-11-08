<?php

namespace App\Libraries\Queue\Jobs;

use App\Libraries\Queue\JobInterface;

/**
 * Job asincrono per l'invio di email.
 *
 * Esempio d'uso:
 *   RedisQueue::instance()->push(new SendEmailJob(
 *       to: 'cliente@example.com',
 *       subject: 'Fattura n. 42',
 *       body: $htmlBody,
 *       attachments: ['/path/to/fattura.pdf'],
 *   ), 'email');
 */
class SendEmailJob implements JobInterface
{
    /**
     * @param string[] $attachments Percorsi assoluti dei file allegati
     */
    public function __construct(
        private string $to,
        private string $subject,
        private string $body,
        private array  $attachments = [],
    ) {}

    public function handle(): void
    {
        $email = \Config\Services::email(null, false);
        $email->setTo($this->to);
        $email->setSubject($this->subject);
        $email->setMessage($this->body);

        foreach ($this->attachments as $path) {
            $email->attach($path);
        }

        if (!$email->send(false)) {
            throw new \RuntimeException('Invio email fallito verso ' . $this->to . ': ' . $email->printDebugger(['headers']));
        }

        log_message('info', 'Email inviata a {to}: {subject}', [
            'to'      => $this->to,
            'subject' => $this->subject,
        ]);
    }
}
