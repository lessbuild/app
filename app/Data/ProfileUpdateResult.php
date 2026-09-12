<?php

namespace App\Data;

class ProfileUpdateResult
{
    /** Carry email-change and verification-delivery outcomes back to the HTTP response boundary. */
    public function __construct(
        public readonly bool $emailChanged,
        public readonly bool $verificationSent = false,
    ) {}
}
