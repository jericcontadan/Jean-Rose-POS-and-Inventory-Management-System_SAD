<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Deployment check (docs/07-deployment.md): on the server the reset email link must use APP_URL,
// even when someone sends a fake Host header. Mail::fake() means no real email is sent.
class ResetLinkUsesAppUrlTest extends TestCase
{
    use CreatesPosData, DatabaseTransactions;

    public function test_reset_link_uses_app_url_not_the_host_header_in_production(): void
    {
        Mail::fake();
        config([
            'app.url' => 'https://pos.example.test',
            'pos.mail_via' => 'smtp',      // no Brevo call: the email goes to the faked Laravel mailer
            'services.brevo.key' => null,
        ]);

        // Act like the server: production mode, then run the provider's boot() again so it forces APP_URL.
        $this->app['env'] = 'production';
        $this->app->getProvider(AppServiceProvider::class)->boot();

        // Back to 'testing' before the request: Laravel only skips the CSRF check in tests when env is 'testing'
        // (in production the POST would get 419). The forced APP_URL stays on the URL generator.
        $this->app['env'] = 'testing';

        $user = $this->makeUser('OWNER');

        // A full URL, so the request really arrives with another website as its Host.
        $this->post('http://evil.example.com/forgot-password', ['email' => $user->email, 'by' => 'email']);

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            return str_starts_with($mail->url, 'https://pos.example.test/reset-password/')
                && ! str_contains($mail->url, 'evil.example.com');
        });
    }
}
