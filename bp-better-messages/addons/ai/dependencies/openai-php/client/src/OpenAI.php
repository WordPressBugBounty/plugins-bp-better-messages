<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

use BetterMessages\OpenAI\Client;
use BetterMessages\OpenAI\Factory;

final class BetterMessages_OpenAI
{
    /**
     * Creates a new Open AI Client with the given API token.
     */
    public static function client(string $apiKey, ?string $organization = null): Client
    {
        return self::factory()
            ->withApiKey($apiKey)
            ->withOrganization($organization)
            ->withHttpHeader('BetterMessages_OpenAI-Beta', 'assistants=v1')
            ->make();
    }

    /**
     * Creates a new factory instance to configure a custom Open AI Client
     */
    public static function factory(): Factory
    {
        return new Factory();
    }
}
