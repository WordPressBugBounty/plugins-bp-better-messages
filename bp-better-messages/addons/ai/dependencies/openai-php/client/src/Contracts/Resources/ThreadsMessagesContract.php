<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\OpenAI\Contracts\Resources;

use BetterMessages\OpenAI\Responses\Threads\Messages\ThreadMessageListResponse;
use BetterMessages\OpenAI\Responses\Threads\Messages\ThreadMessageResponse;

interface ThreadsMessagesContract
{
    /**
     * Create a message.
     *
     * @see https://platform.openai.com/docs/api-reference/messages/createMessage
     *
     * @param  array<string, mixed>  $parameters
     */
    public function create(string $threadId, array $parameters): ThreadMessageResponse;

    /**
     * Retrieve a message.
     *
     * @see https://platform.openai.com/docs/api-reference/messages/getMessage
     */
    public function retrieve(string $threadId, string $messageId): ThreadMessageResponse;

    /**
     * Modifies a message.
     *
     * @see https://platform.openai.com/docs/api-reference/messages/modifyMessage
     *
     * @param  array<string, mixed>  $parameters
     */
    public function modify(string $threadId, string $messageId, array $parameters): ThreadMessageResponse;

    /**
     * Returns a list of messages for a given thread.
     *
     * @see https://platform.openai.com/docs/api-reference/messages/listMessages
     *
     * @param  array<string, mixed>  $parameters
     */
    public function list(string $threadId, array $parameters = []): ThreadMessageListResponse;

    /**
     * Manage files attached to a thread message.
     *
     * @see https://platform.openai.com/docs/api-reference/messages/file-object
     */
    public function files(): ThreadsMessagesFilesContract;
}
