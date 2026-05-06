<?php

declare(strict_types=1);

namespace AudD\Internal;

/**
 * Cost-aware retry policy. See design spec §7.1.
 *
 * READ        — idempotent reads (streams.list, getCallbackUrl): retry on
 *               408/429/5xx + any connection error.
 * RECOGNITION — recognize, recognize_enterprise, advanced.find_lyrics: retry
 *               on pre-upload connection failures + 5xx. DO NOT retry on
 *               read-timeout-after-upload (cost protection — server may have
 *               already done the metered work).
 * MUTATING    — streams.add, streams.set_url, streams.delete, set_callback_url,
 *               custom_catalog.add: retry only on pre-upload connection
 *               failures. DO NOT retry 5xx (server-side side-effect may have
 *               happened).
 *
 * @internal
 */
enum RetryClass: string
{
    case READ = 'read';
    case RECOGNITION = 'recognition';
    case MUTATING = 'mutating';
}
