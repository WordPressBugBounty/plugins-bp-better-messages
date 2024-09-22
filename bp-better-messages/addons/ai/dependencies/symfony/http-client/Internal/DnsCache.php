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
 * Cache for resolved DNS queries.
 *
 * @author Alexander M. Turek <me@derrabus.de>
 *
 * @internal
 */
final class DnsCache
{
    /**
     * Resolved hostnames (hostname => IP address).
     *
     * @var string[]
     */
    public array $hostnames = [];

    /**
     * @var string[]
     */
    public array $removals = [];

    /**
     * @var string[]
     */
    public array $evictions = [];
}
