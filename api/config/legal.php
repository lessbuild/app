<?php

declare(strict_types=1);

// The public privacy policy and terms of service. Standard wording drafted for BuildPusher's owner to review; the
// effective date and contact address come from the environment.
return [
    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '2026-09-28'),
    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'support@buildpusher.com'),

    'pages' => [
        'privacy' => [
            'title' => 'Privacy policy',
            'description' => 'How BuildPusher collects, uses and protects information about you and the services you run.',
            'sections' => [
                ['Information we process', 'Account details (your name, email address and sign-in and security records), account membership and roles, and the records our services keep for you: servers and websites, deployment history, logs you ask for, check results, incidents and alerts, telemetry you send, and aggregated website analytics. We also keep the encrypted credentials needed for the actions you authorise, such as cloud provider tokens and deploy keys. Card payments are handled by Stripe; we never see or store full card numbers.'],
                ['How we use it', 'To sign you in and keep your account secure, run the deployments, servers, checks and reports you ask for, send the alerts and emails you set up, bill you, answer support requests, prevent abuse, and keep the platform reliable. We don’t sell personal information and don’t use your application data for advertising.'],
                ['Website analytics', 'The Analytics tracker sets no cookies and stores no personal data. Visitors are counted with a hash that rotates every day, so nobody can be followed from one day to the next or across websites. Each site owner decides what to measure and is responsible for their own notices to visitors.'],
                ['Storage and sharing', 'Secrets, scripts and credentials are encrypted at rest. We share information only with the providers needed to deliver the features you choose (cloud hosting, source control, email delivery, DNS and payments), or when the law requires it. Providers you connect handle requests under their own terms.'],
                ['Retention', 'Operational records are kept for as long as your plan’s retention allows, then deleted. Security records such as sign-in history are kept for a limited period. When you delete your account, its data is removed, except where we must keep records such as invoices for legal reasons.'],
                ['Your choices', 'You can download a copy of your data and delete your account from your privacy settings, revoke sessions and API tokens, disconnect providers, and change which notifications you get. You can also contact us to ask for access, correction or deletion of your personal information.'],
            ],
        ],
        'terms' => [
            'title' => 'Terms of service',
            'description' => 'The terms for using BuildPusher’s Deploy, Infrastructure, Monitoring and Analytics services.',
            'sections' => [
                ['Using BuildPusher', 'Give accurate account information, keep your password, recovery codes and API tokens safe, and use BuildPusher only for systems you’re authorised to manage. You’re responsible for what happens through your account and the provider accounts you connect.'],
                ['Acceptable use', 'Don’t use the service to break the law, attack or compromise systems, distribute malware, send spam, get around provider limits, interfere with other customers, or host content that infringes other people’s rights. We may limit or suspend access when that’s needed to protect the service or others.'],
                ['Your infrastructure and content', 'Your code and data stay yours. Servers run in the cloud accounts you connect, and their charges, availability, domains, licences, backups and the content you deploy remain your responsibility.'],
                ['Plans and billing', 'Each service has a free tier and paid plans, billed together on one monthly subscription per account. Prices, limits and renewal terms are shown on the pricing page and at checkout. You can change or cancel a plan at any time; a cancelled plan stays active until the end of the period you’ve paid for.'],
                ['Operational safety', 'Deployments, commands, restores, scaling and deletions change live systems. Check what you’re changing and keep your own backups. BuildPusher provides safeguards and records, but can’t guarantee that every script, application or third-party service behaves as expected.'],
                ['Availability and closing your account', 'The service may change or be interrupted, and we may restrict activity that isn’t safe. You can stop using BuildPusher and delete your account at any time. Deleting your account doesn’t remove servers or other resources that live in your own provider accounts.'],
                ['Warranty and liability', 'As far as the law allows, BuildPusher is provided without a warranty that it will be uninterrupted or error-free, and we aren’t liable for indirect or consequential loss, lost profits, or outages at third-party providers. Rights that can’t be excluded by law are unaffected.'],
            ],
        ],
    ],
];
