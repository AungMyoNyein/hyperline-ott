<?php

namespace App\Exceptions;

use App\Support\Entitlements;
use RuntimeException;

class PlaybackDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason = Entitlements::DENY_SUBSCRIPTION_EXPIRED,
        string $message = 'Playback is not permitted for this viewer.',
    ) {
        parent::__construct($message);
    }
}
