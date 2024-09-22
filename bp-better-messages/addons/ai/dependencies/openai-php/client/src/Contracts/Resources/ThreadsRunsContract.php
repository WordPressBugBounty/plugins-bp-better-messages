<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\OpenAI\Contracts\Resources;

use BetterMessages\OpenAI\Responses\Threads\Runs\ThreadRunListResponse;
use BetterMessages\OpenAI\Responses\Threads\Runs\ThreadRunResponse;

interface ThreadsRunsContract
{
    /**
     * Create a run.
     *
     * @see https://platform.openai.com/docs/api-reference/runs/createRun
     *
     * @param  array<string, mixed>  $parameters
     */
    public function create(string $threadId, array $parameters): ThreadRunResponse;

    /**
     * Retrieves a run.
     *
     * @see https://platform.openai.com/docs/api-reference/runs/getRun
     */
    public function retrieve(string $threadId, string $runId): ThreadRunResponse;

    /**
     * Modifies a run.
     *
     * @see https://platform.openai.com/docs/api-reference/runs/modifyRun
     *
     * @param  array<string, mixed>  $parameters
     */
    public function modify(string $threadId, string $runId, array $parameters): ThreadRunResponse;

    /**
     * This endpoint can be used to submit the outputs from the tool calls once they're all completed.
     *
     * @see https://platform.openai.com/docs/api-reference/runs/submitToolOutputs
     *
     * @param  array<string, mixed>  $parameters
     */
    public function submitToolOutputs(string $threadId, string $runId, array $parameters): ThreadRunResponse;

    /**
     * Cancels a run that is `in_progress`.
     *
     * @see https://platform.openai.com/docs/api-reference/runs/cancelRun
     */
    public function cancel(string $threadId, string $runId): ThreadRunResponse;

    /**
     * Returns a list of runs belonging to a thread.
     *
     * @see https://platform.openai.com/docs/api-reference/runs/listRuns
     *
     * @param  array<string, mixed>  $parameters
     */
    public function list(string $threadId, array $parameters = []): ThreadRunListResponse;

    /**
     * Get steps attached to a run.
     *
     * @see https://platform.openai.com/docs/api-reference/runs/step-object
     */
    public function steps(): ThreadsRunsStepsContract;
}
