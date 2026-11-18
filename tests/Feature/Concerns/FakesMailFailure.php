<?php

namespace Tests\Feature\Concerns;

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

// Makes every email fail the way Gmail SMTP did:
// "Expected response code 250 but got empty code". Nothing is really sent.
// It puts a fresh, real mail manager in place (this also undoes an earlier Mail::fake()).
trait FakesMailFailure
{
    protected function makeMailFail(): void
    {
        config([
            'mail.mailers.failing' => ['transport' => 'failing'],
            'mail.default' => 'failing',
        ]);

        $manager = new MailManager($this->app);
        $manager->extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new UnexpectedResponseException('Expected response code "250" but got empty code.');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });

        Mail::swap($manager);
    }
}
