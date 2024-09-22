<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace BetterMessages\OpenAI\Responses\Concerns;

use BetterMessages\OpenAI\Responses\Meta\MetaInformation;

trait HasMetaInformation
{
    public function meta(): MetaInformation
    {
        return $this->meta;
    }
}
