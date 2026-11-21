<?php

namespace Tests\Feature;

use App\Services\EmailDeliveryConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailDeliveryConfigurationTest extends TestCase
{
    #[DataProvider('mailerConfigurations')]
    public function test_delivery_configuration_is_detected(array $mailers, array $services, bool $expected): void
    {
        config()->set('mail.default', 'test');
        config()->set('mail.mailers', $mailers);
        config()->set('services', $services);

        $this->assertSame($expected, app(EmailDeliveryConfiguration::class)->isConfigured());
    }

    public static function mailerConfigurations(): array
    {
        return [
            'log' => [['test' => ['transport' => 'log']], [], false],
            'array' => [['test' => ['transport' => 'array']], [], false],
            'missing mailer' => [[], [], false],
            'SMTP without host' => [['test' => ['transport' => 'smtp', 'host' => '']], [], false],
            'SMTP relay without credentials' => [['test' => ['transport' => 'smtp', 'host' => 'localhost']], [], true],
            'SMTP URL' => [['test' => ['transport' => 'smtp', 'url' => 'smtp://localhost:1025']], [], true],
            'Postmark without key' => [['test' => ['transport' => 'postmark']], [], false],
            'Postmark with key' => [['test' => ['transport' => 'postmark']], ['postmark' => ['key' => 'test-key']], true],
            'Resend without key' => [['test' => ['transport' => 'resend']], [], false],
            'Resend with key' => [['test' => ['transport' => 'resend']], ['resend' => ['key' => 'test-key']], true],
            'Mailgun without domain' => [['test' => ['transport' => 'mailgun']], ['mailgun' => ['secret' => 'test-key']], false],
            'SES with ambient credentials' => [['test' => ['transport' => 'ses']], [], true],
            'sendmail' => [['test' => ['transport' => 'sendmail']], [], true],
            'non-delivering failover' => [[
                'test' => ['transport' => 'failover', 'mailers' => ['log']],
                'log' => ['transport' => 'log'],
            ], [], false],
            'delivering failover' => [[
                'test' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
                'smtp' => ['transport' => 'smtp', 'host' => 'localhost'],
                'log' => ['transport' => 'log'],
            ], [], true],
            'cyclic composite' => [['test' => ['transport' => 'roundrobin', 'mailers' => ['test']]], [], false],
        ];
    }
}
