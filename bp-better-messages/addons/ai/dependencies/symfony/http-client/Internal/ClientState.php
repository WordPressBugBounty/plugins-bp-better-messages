<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\Symfony\Component\HttpClient\Internal;

/**
 * Internal representation of the client state.
 *
 * @author Alexander M. Turek <me@derrabus.de>
 *
 * @internal
 */
class ClientState
{
    public array $handlesActivity = [];
    public array $openHandles = [];
    public ?float $lastTimeout = null;
}
