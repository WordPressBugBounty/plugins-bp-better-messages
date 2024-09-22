<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\OpenAI\Testing\Responses\Fixtures\Threads\Messages;

final class ThreadMessageListResponseFixture
{
    public const ATTRIBUTES = [
        'object' => 'list',
        'data' => [
            [
                'id' => 'msg_KNsDDwE41BUAHhcPNpDkdHWZ',
                'object' => 'thread.message',
                'created_at' => 1_699_623_839,
                'thread_id' => 'thread_agvtHUGezjTCt4SKgQg0NJ2Y',
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => [
                            'value' => 'How does AI work? Explain it in simple terms.',
                            'annotations' => [
                            ],
                        ],
                    ],
                ],
                'file_ids' => [
                    'file-DhxjnFCaSHc4ZELRGKwTMFtI',
                ],
                'assistant_id' => null,
                'run_id' => null,
                'metadata' => [],
            ],
        ],
        'first_id' => 'msg_KNsDDwE41BUAHhcPNpDkdHWZ',
        'last_id' => 'msg_KNsDDwE41BUAHhcPNpDkdHWZ',
        'has_more' => false,
    ];
}
