<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\OpenAI\Testing\Responses\Fixtures\Embeddings;

final class CreateResponseFixture
{
    public const ATTRIBUTES = [
        'object' => 'list',
        'data' => [
            [
                'object' => 'embedding',
                'index' => 0,
                'embedding' => [
                    -0.008906792,
                    -0.013743395,
                ],
            ],
        ],
        'usage' => [
            'prompt_tokens' => 8,
            'total_tokens' => 8,
        ],
    ];
}
