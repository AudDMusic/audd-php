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
 * MUTATING    — streams.add, streams.set_url, streams.delete, set_callback_url:
 *               retry only on pre-upload connection failures. DO NOT retry 5xx
 *               (server-side side-effect may have happened). Server-idempotent
 *               on radio_id, so a connect-stage retry is safe.
 * NONE        — custom_catalog.add: never retry. The upload is metered and
 *               a transport-level retry could double-charge for the same
 *               fingerprinting work. The first transient failure surfaces as
 *               a clean exception for the caller to decide on.
 *
 * @internal
 */
enum RetryClass: string
{
    case READ = 'read';
    case RECOGNITION = 'recognition';
    case MUTATING = 'mutating';
    case NONE = 'none';
}
