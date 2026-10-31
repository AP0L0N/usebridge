<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Services\Auth\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Mail domain pending: MAIL_MAILER=log writes verification / reset mails to the mail log only; sender from config. */
class MailConfigTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $mailLog;

    private TestHandler $appLog;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'mail-capture',
            'mail.from' => ['address' => 'bridge@mail.example.test', 'name' => 'Bridge Test'],
            'logging.channels.mail-capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'logging.channels.app-capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
        Log::setDefaultDriver('app-capture');
        $this->mailLog = Log::channel('mail-capture')->getLogger()->getHandlers()[0];
        $this->appLog = Log::channel('app-capture')->getLogger()->getHandlers()[0];
    }

    #[Test]
    public function the_sender_reads_mail_from_address_and_name_with_bridge_defaults(): void
    {
        $config = file_get_contents(config_path('mail.php'));
        $this->assertStringContainsString("'address' => env('MAIL_FROM_ADDRESS') ?: 'bridge@localhost'", $config);
        $this->assertStringContainsString("'name' => env('MAIL_FROM_NAME') ?: 'Bridge'", $config);
        $example = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^MAIL_MAILER=log$/m', $example);
        $this->assertMatchesRegularExpression('/^MAIL_FROM_ADDRESS=$/m', $example);
        $this->assertMatchesRegularExpression('/^MAIL_FROM_NAME="Bridge"$/m', $example);
    }

    #[Test]
    public function verification_and_reset_mails_go_to_the_log_transport_from_the_configured_sender_and_nowhere_else(): void
    {
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $e) use (&$sent) {
            $sent[] = $e->message;
        });
        $this->assertInstanceOf(LogTransport::class, Mail::mailer()->getSymfonyTransport());

        $user = User::factory()->unverified()->create(['email' => 'new@example.test']);
        $user->sendEmailVerificationNotification();
        app(AccountService::class)->sendPasswordResetLink('new@example.test');

        $this->assertCount(2, $sent);
        foreach ($sent as $message) {
            $this->assertSame('bridge@mail.example.test', $message->getFrom()[0]->getAddress());
            $this->assertSame('Bridge Test', $message->getFrom()[0]->getName());
            $this->assertSame('new@example.test', $message->getTo()[0]->getAddress());
        }

        $mailText = implode("\n", array_map(fn ($r) => $r->message, $this->mailLog->getRecords()));
        $this->assertStringContainsString('/email/verify/', quoted_printable_decode($mailText));
        $this->assertStringContainsString('/reset-password/', quoted_printable_decode($mailText));
        $appText = implode("\n", array_map(fn ($r) => $r->message.json_encode($r->context), $this->appLog->getRecords()));
        $this->assertStringNotContainsString('/email/verify/', $appText);
        $this->assertStringNotContainsString('/reset-password/', $appText);
    }
}
