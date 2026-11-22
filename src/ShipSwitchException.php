<?php

declare(strict_types=1);

namespace ShipSwitch;

/**
 * Why ShipSwitch could not be read. `reason` is one of the constants below.
 */
final class ShipSwitchException extends \RuntimeException
{
    /** No answer within the timeout. */
    public const TIMEOUT = 'TIMEOUT';
    /** The API could not be reached. */
    public const NETWORK = 'NETWORK';
    /** The API key is missing, unknown or revoked. */
    public const UNAUTHORIZED = 'UNAUTHORIZED';
    /** The API answered with an error status. */
    public const HTTP = 'HTTP';
    /** The answer could not be understood. */
    public const INVALID_RESPONSE = 'INVALID_RESPONSE';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
