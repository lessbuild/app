<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

final class PaymentProviderUnavailable extends RuntimeException {}
