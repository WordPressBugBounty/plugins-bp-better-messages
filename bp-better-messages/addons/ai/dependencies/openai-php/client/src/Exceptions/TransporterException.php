<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace BetterMessages\OpenAI\Exceptions;

use Exception;
use BetterMessages\Psr\Http\Client\ClientExceptionInterface;

final class TransporterException extends Exception
{
    /**
     * Creates a new Exception instance.
     */
    public function __construct(ClientExceptionInterface $exception)
    {
        parent::__construct($exception->getMessage(), 0, $exception);
    }
}
