<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace BetterMessages\OpenAI\Responses\Assistants;

use BetterMessages\OpenAI\Contracts\ResponseContract;
use BetterMessages\OpenAI\Contracts\ResponseHasMetaInformationContract;
use BetterMessages\OpenAI\Responses\Concerns\ArrayAccessible;
use BetterMessages\OpenAI\Responses\Concerns\HasMetaInformation;
use BetterMessages\OpenAI\Responses\Meta\MetaInformation;
use BetterMessages\OpenAI\Testing\Responses\Concerns\Fakeable;

/**
 * @implements ResponseContract<array{id: string, object: string, created_at: int, name: ?string, description: ?string, model: string, instructions: ?string, tools: array<int, array{type: string}|array{type: string}|array{type: string, function: array{description: string, name: string, parameters: array<string, mixed>}}>, file_ids: array<int, string>, metadata: array<string, string>}>
 */
final class AssistantResponse implements ResponseContract, ResponseHasMetaInformationContract
{
    /**
     * @use ArrayAccessible<array{id: string, object: string, created_at: int, name: ?string, description: ?string, model: string, instructions: ?string, tools: array<int, array{type: string}|array{type: string}|array{type: string, function: array{description: string, name: string, parameters: array<string, mixed>}}>, file_ids: array<int, string>, metadata: array<string, string>}>
     */
    use ArrayAccessible;

    use Fakeable;
    use HasMetaInformation;

    /**
     * @param  array<int, AssistantResponseToolCodeInterpreter|AssistantResponseToolRetrieval|AssistantResponseToolFunction>  $tools
     * @param  array<int, string>  $fileIds
     * @param  array<string, string>  $metadata
     */
    private function __construct(
        public string $id,
        public string $object,
        public int $createdAt,
        public ?string $name,
        public ?string $description,
        public string $model,
        public ?string $instructions,
        public array $tools,
        public array $fileIds,
        public array $metadata,
        private readonly MetaInformation $meta,
    ) {
    }

    /**
     * Acts as static factory, and returns a new Response instance.
     *
     * @param  array{id: string, object: string, created_at: int, name: ?string, description: ?string, model: string, instructions: ?string, tools: array<int, array{type: 'code_interpreter'}|array{type: 'retrieval'}|array{type: 'function', function: array{description: string, name: string, parameters: array<string, mixed>}}>, file_ids: array<int, string>, metadata: array<string, string>}  $attributes
     */
    public static function from(array $attributes, MetaInformation $meta): self
    {
        $tools = array_map(
            fn (array $tool): AssistantResponseToolCodeInterpreter|AssistantResponseToolRetrieval|AssistantResponseToolFunction => match ($tool['type']) {
                'code_interpreter' => AssistantResponseToolCodeInterpreter::from($tool),
                'retrieval' => AssistantResponseToolRetrieval::from($tool),
                'function' => AssistantResponseToolFunction::from($tool),
            },
            $attributes['tools'],
        );

        return new self(
            $attributes['id'],
            $attributes['object'],
            $attributes['created_at'],
            $attributes['name'],
            $attributes['description'],
            $attributes['model'],
            $attributes['instructions'],
            $tools,
            $attributes['file_ids'],
            $attributes['metadata'],
            $meta,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'object' => $this->object,
            'created_at' => $this->createdAt,
            'name' => $this->name,
            'description' => $this->description,
            'model' => $this->model,
            'instructions' => $this->instructions,
            'tools' => array_map(fn (AssistantResponseToolCodeInterpreter|AssistantResponseToolRetrieval|AssistantResponseToolFunction $tool): array => $tool->toArray(), $this->tools),
            'file_ids' => $this->fileIds,
            'metadata' => $this->metadata,
        ];
    }
}
