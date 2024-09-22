<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\OpenAI\Testing\Responses\Fixtures\Models;

final class ListResponseFixture
{
    public const ATTRIBUTES = [
        'object' => 'list',
        'data' => [
            RetrieveResponseFixture::ATTRIBUTES,
        ],
    ];
}
