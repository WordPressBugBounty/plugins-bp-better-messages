<?php

namespace BetterMessages\OpenAI\Contracts\Resources;

use BetterMessages\OpenAI\Responses\Assistants\AssistantDeleteResponse;
use BetterMessages\OpenAI\Responses\Assistants\AssistantListResponse;
use BetterMessages\OpenAI\Responses\Assistants\AssistantResponse;

interface AssistantsContract
{
    /**
     * Create an assistant with a model and instructions.
     *
     * @see https://platform.openai.com/docs/api-reference/assistants/createAssistant
     *
     * @param  array<string, mixed>  $parameters
     */
    public function create(array $parameters): AssistantResponse;

    /**
     * Retrieves an assistant.
     *
     * @see https://platform.openai.com/docs/api-reference/assistants/getAssistant
     */
    public function retrieve(string $id): AssistantResponse;

    /**
     * Modifies an assistant.
     *
     * @see https://platform.openai.com/docs/api-reference/assistants/modifyAssistant
     *
     * @param  array<string, mixed>  $parameters
     */
    public function modify(string $id, array $parameters): AssistantResponse;

    /**
     * Delete an assistant.
     *
     * @see https://platform.openai.com/docs/api-reference/assistants/deleteAssistant
     */
    public function delete(string $id): AssistantDeleteResponse;

    /**
     * Returns a list of assistants.
     *
     * @see https://platform.openai.com/docs/api-reference/assistants/listAssistants
     *
     * @param  array<string, mixed>  $parameters
     */
    public function list(array $parameters = []): AssistantListResponse;
}
