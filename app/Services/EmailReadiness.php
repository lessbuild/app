<?php

namespace App\Services;

class EmailReadiness
{
    /** @return array{name: string, passed: bool, detail: string} */
    public function check(): array
    {
        if (config('app.env') !== 'production') {
            return [
                'name' => 'Email delivery',
                'passed' => true,
                'detail' => 'Production delivery is not required in this environment',
            ];
        }

        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $address = (string) config('mail.from.address');
        $domain = strtolower((string) substr(strrchr($address, '@') ?: '', 1));
        $productionTransport = ! in_array($transport, ['array', 'log'], true);
        $validSender = filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            && ! in_array($domain, ['', 'example.com', 'example.test'], true);
        $configured = $productionTransport && $validSender;

        if ($transport === 'smtp') {
            $host = strtolower((string) config("mail.mailers.{$mailer}.host"));
            $configured = $configured
                && filled($host)
                && ! in_array($host, ['localhost', '127.0.0.1', 'mailhog'], true)
                && filled(config("mail.mailers.{$mailer}.username"))
                && filled(config("mail.mailers.{$mailer}.password"));
        }

        return [
            'name' => 'Email delivery',
            'passed' => $configured,
            'detail' => $configured
                ? 'Production transport and sender are configured'
                : 'Configure a production mail transport and verified sender',
        ];
    }
}
