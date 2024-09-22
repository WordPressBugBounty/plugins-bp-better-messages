<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\OpenAI\Testing\Responses\Fixtures\FineTuning;

final class ListJobsResponseFixture
{
    public const ATTRIBUTES = [
        'object' => 'list',
        'data' => [
            RetrieveJobResponseFixture::ATTRIBUTES,
        ],
        'has_more' => false,
    ];
}
