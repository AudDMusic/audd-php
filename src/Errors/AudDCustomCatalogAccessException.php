<?php

declare(strict_types=1);

namespace AudD\Errors;

/**
 * 904 raised specifically from custom_catalog.* — overridden message that
 * makes the not-for-recognition framing unambiguous.
 */
final class AudDCustomCatalogAccessException extends AudDSubscriptionException
{
    public readonly string $serverMessage;

    /**
     * @param array<string, mixed> $requestedParams
     * @param mixed                $rawResponse
     */
    public function __construct(
        int $errorCode,
        string $serverMessage,
        int $httpStatus,
        ?string $requestId = null,
        array $requestedParams = [],
        ?string $requestMethod = null,
        ?string $brandedMessage = null,
        mixed $rawResponse = null,
    ) {
        $this->serverMessage = $serverMessage;
        $message = "Adding songs to your custom catalog requires enterprise access that isn't "
            . "enabled on your account.\n\n"
            . 'Note: the custom-catalog endpoint is for adding songs to your private '
            . 'fingerprint database, not for music recognition. If you intended to '
            . 'identify music, use recognize(...) (or recognizeEnterprise(...) for '
            . "files longer than 25 seconds) instead.\n\n"
            . "To request custom-catalog access, contact api@audd.io.\n\n"
            . '[Server message: ' . $serverMessage . ']';
        parent::__construct(
            errorCode: $errorCode,
            apiMessage: $message,
            httpStatus: $httpStatus,
            requestId: $requestId,
            requestedParams: $requestedParams,
            requestMethod: $requestMethod,
            brandedMessage: $brandedMessage,
            rawResponse: $rawResponse,
        );
    }
}
