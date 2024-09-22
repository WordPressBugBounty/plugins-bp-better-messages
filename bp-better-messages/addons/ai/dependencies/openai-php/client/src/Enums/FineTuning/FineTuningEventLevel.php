<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\OpenAI\Enums\FineTuning;

enum FineTuningEventLevel: string
{
    case Info = 'info';
    case Warning = 'warn';
    case Error = 'error';
}
