<?php

declare(strict_types=1);

namespace BetterMessages\OpenAI\Responses\Audio;

use BetterMessages\OpenAI\Contracts\ResponseContract;
use BetterMessages\OpenAI\Contracts\ResponseHasMetaInformationContract;
use BetterMessages\OpenAI\Responses\Concerns\ArrayAccessible;
use BetterMessages\OpenAI\Responses\Concerns\HasMetaInformation;
use BetterMessages\OpenAI\Responses\Meta\MetaInformation;
use BetterMessages\OpenAI\Testing\Responses\Concerns\Fakeable;

/**
 * @implements ResponseContract<array{task: ?string, language: ?string, duration: ?float, segments: array<int, array{id: int, seek: int, start: float, end: float, text: string, tokens: array<int, int>, temperature: float, avg_logprob: float, compression_ratio: float, no_speech_prob: float, transient?: bool}>, text: string}>
 */
final class TranslationResponse implements ResponseContract, ResponseHasMetaInformationContract
{
    /**
     * @use ArrayAccessible<array{task: ?string, language: ?string, duration: ?float, segments: array<int, array{id: int, seek: int, start: float, end: float, text: string, tokens: array<int, int>, temperature: float, avg_logprob: float, compression_ratio: float, no_speech_prob: float, transient?: bool}>, text: string}>
     */
    use ArrayAccessible;

    use Fakeable;
    use HasMetaInformation;

    /**
     * @param  array<int, TranslationResponseSegment>  $segments
     */
    private function __construct(
        public readonly ?string $task,
        public readonly ?string $language,
        public readonly ?float $duration,
        public readonly array $segments,
        public readonly string $text,
        private readonly MetaInformation $meta,
    ) {}

    /**
     * Acts as static factory, and returns a new Response instance.
     *
     * @param  array{task: ?string, language: ?string, duration: ?float, segments: array<int, array{id: int, seek: int, start: float, end: float, text: string, tokens: array<int, int>, temperature: float, avg_logprob: float, compression_ratio: float, no_speech_prob: float, transient?: bool}>, text: string}  $attributes
     */
    public static function from(array|string $attributes, MetaInformation $meta): self
    {
        if (is_string($attributes)) {
            $attributes = ['text' => $attributes];
        }

        $segments = isset($attributes['segments']) ? array_map(fn (array $result): TranslationResponseSegment => TranslationResponseSegment::from(
            $result
        ), $attributes['segments']) : [];

        return new self(
            $attributes['task'] ?? null,
            $attributes['language'] ?? null,
            $attributes['duration'] ?? null,
            $segments,
            $attributes['text'],
            $meta,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'task' => $this->task,
            'language' => $this->language,
            'duration' => $this->duration,
            'segments' => array_map(
                static fn (TranslationResponseSegment $result): array => $result->toArray(),
                $this->segments,
            ),
            'text' => $this->text,
        ];
    }
}
