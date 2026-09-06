# Production email readiness

BuildPusher uses email for verification, password resets, workspace invitations, and security notifications. Production must not use the `log`, `array`, or local MailHog transports.

## Configure

Set a production SMTP or supported API transport in `.env`. For SMTP, configure `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`. The sender domain must be verified with the mail provider.

Publish the SPF and DKIM records supplied by the provider. Publish a DMARC record at `_dmarc.buildpusher.com`, beginning in reporting mode and strengthening the policy after delivery has been observed.

## Verify

Run the non-delivering configuration check:

```bash
php artisan buildpusher:email:diagnose
```

After the sender and DNS records are verified, send one deliberate test:

```bash
php artisan buildpusher:email:diagnose --send-to=operator@example.net
```

Confirm inbox placement and inspect the received message headers for SPF, DKIM, and DMARC passes. Then exercise registration verification, password reset, invitation, security alert, and failed-delivery recovery flows in the staging environment.
