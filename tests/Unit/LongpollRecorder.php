<?php

declare(strict_types=1);

namespace AudD\Tests\Unit;

use AudD\Models\StreamCallbackMatch;
use AudD\Models\StreamCallbackNotification;

/**
 * Test helper: what a longpoll loop delivered to its handlers.
 */
final class LongpollRecorder
{
    /** @var list<StreamCallbackMatch> */
    public array $matches = [];

    /** @var list<StreamCallbackNotification> */
    public array $notifications = [];

    /** @var list<\Throwable> */
    public array $errors = [];
}
