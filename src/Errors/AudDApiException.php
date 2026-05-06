<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * Server returned status=error. Carries the AudD numeric error code + the full echo.
 */
class AudDApiException extends AudDException
{
    /** @var array<string, mixed> */
    public readonly array $requestedParams;

    /** @var mixed */
    public readonly mixed $rawResponse;

    /**
     * @param array<string, mixed> $requestedParams
     * @param mixed                $rawResponse
     */
    public function __construct(
        public readonly int $errorCode,
        public readonly string $apiMessage,
        public readonly int $httpStatus,
        public readonly ?string $requestId = null,
        array $requestedParams = [],
        public readonly ?string $requestMethod = null,
        public readonly ?string $brandedMessage = null,
        mixed $rawResponse = null,
    ) {
        $this->requestedParams = $requestedParams;
        $this->rawResponse = $rawResponse;
        parent::__construct(sprintf('[#%d] %s', $errorCode, $apiMessage));
    }
}
