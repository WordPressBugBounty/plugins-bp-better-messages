<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\OpenAI\Testing\Resources;

use BetterMessages\OpenAI\Contracts\Resources\ThreadsMessagesContract;
use BetterMessages\OpenAI\Resources\ThreadsMessages;
use BetterMessages\OpenAI\Responses\Threads\Messages\ThreadMessageListResponse;
use BetterMessages\OpenAI\Responses\Threads\Messages\ThreadMessageResponse;
use BetterMessages\OpenAI\Testing\Resources\Concerns\Testable;

final class ThreadsMessagesTestResource implements ThreadsMessagesContract
{
    use Testable;

    public function resource(): string
    {
        return ThreadsMessages::class;
    }

    public function create(string $threadId, array $parameters): ThreadMessageResponse
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function retrieve(string $threadId, string $messageId): ThreadMessageResponse
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function modify(string $threadId, string $messageId, array $parameters): ThreadMessageResponse
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function list(string $threadId, array $parameters = []): ThreadMessageListResponse
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function files(): ThreadsMessagesFilesTestResource
    {
        return new ThreadsMessagesFilesTestResource($this->fake);
    }
}
