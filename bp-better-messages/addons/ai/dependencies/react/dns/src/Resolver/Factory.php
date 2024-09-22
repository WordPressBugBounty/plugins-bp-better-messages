<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\React\Dns\Resolver;

use BetterMessages\React\Cache\ArrayCache;
use BetterMessages\React\Cache\CacheInterface;
use BetterMessages\React\Dns\Config\Config;
use BetterMessages\React\Dns\Config\HostsFile;
use BetterMessages\React\Dns\Query\CachingExecutor;
use BetterMessages\React\Dns\Query\CoopExecutor;
use BetterMessages\React\Dns\Query\ExecutorInterface;
use BetterMessages\React\Dns\Query\FallbackExecutor;
use BetterMessages\React\Dns\Query\HostsFileExecutor;
use BetterMessages\React\Dns\Query\RetryExecutor;
use BetterMessages\React\Dns\Query\SelectiveTransportExecutor;
use BetterMessages\React\Dns\Query\TcpTransportExecutor;
use BetterMessages\React\Dns\Query\TimeoutExecutor;
use BetterMessages\React\Dns\Query\UdpTransportExecutor;
use BetterMessages\React\EventLoop\Loop;
use BetterMessages\React\EventLoop\LoopInterface;

final class Factory
{
    /**
     * Creates a DNS resolver instance for the given DNS config
     *
     * As of v1.7.0 it's recommended to pass a `Config` object instead of a
     * single nameserver address. If the given config contains more than one DNS
     * nameserver, all DNS nameservers will be used in order. The primary DNS
     * server will always be used first before falling back to the secondary or
     * tertiary DNS server.
     *
     * @param Config|string  $config DNS Config object (recommended) or single nameserver address
     * @param ?LoopInterface $loop
     * @return \BetterMessages\React\Dns\Resolver\ResolverInterface
     * @throws \InvalidArgumentException for invalid DNS server address
     * @throws \UnderflowException when given DNS Config object has an empty list of nameservers
     */
    public function create($config, LoopInterface $loop = null)
    {
        $executor = $this->decorateHostsFileExecutor($this->createExecutor($config, $loop ?: Loop::get()));

        return new Resolver($executor);
    }

    /**
     * Creates a cached DNS resolver instance for the given DNS config and cache
     *
     * As of v1.7.0 it's recommended to pass a `Config` object instead of a
     * single nameserver address. If the given config contains more than one DNS
     * nameserver, all DNS nameservers will be used in order. The primary DNS
     * server will always be used first before falling back to the secondary or
     * tertiary DNS server.
     *
     * @param Config|string   $config DNS Config object (recommended) or single nameserver address
     * @param ?LoopInterface  $loop
     * @param ?CacheInterface $cache
     * @return \BetterMessages\React\Dns\Resolver\ResolverInterface
     * @throws \InvalidArgumentException for invalid DNS server address
     * @throws \UnderflowException when given DNS Config object has an empty list of nameservers
     */
    public function createCached($config, LoopInterface $loop = null, CacheInterface $cache = null)
    {
        // default to keeping maximum of 256 responses in cache unless explicitly given
        if (!($cache instanceof CacheInterface)) {
            $cache = new ArrayCache(256);
        }

        $executor = $this->createExecutor($config, $loop ?: Loop::get());
        $executor = new CachingExecutor($executor, $cache);
        $executor = $this->decorateHostsFileExecutor($executor);

        return new Resolver($executor);
    }

    /**
     * Tries to load the hosts file and decorates the given executor on success
     *
     * @param ExecutorInterface $executor
     * @return ExecutorInterface
     * @codeCoverageIgnore
     */
    private function decorateHostsFileExecutor(ExecutorInterface $executor)
    {
        try {
            $executor = new HostsFileExecutor(
                HostsFile::loadFromPathBlocking(),
                $executor
            );
        } catch (\RuntimeException $e) {
            // ignore this file if it can not be loaded
        }

        // Windows does not store localhost in hosts file by default but handles this internally
        // To compensate for this, we explicitly use hard-coded defaults for localhost
        if (DIRECTORY_SEPARATOR === '\\') {
            $executor = new HostsFileExecutor(
                new HostsFile("127.0.0.1 localhost\n::1 localhost"),
                $executor
            );
        }

        return $executor;
    }

    /**
     * @param Config|string $nameserver
     * @param LoopInterface $loop
     * @return CoopExecutor
     * @throws \InvalidArgumentException for invalid DNS server address
     * @throws \UnderflowException when given DNS Config object has an empty list of nameservers
     */
    private function createExecutor($nameserver, LoopInterface $loop)
    {
        if ($nameserver instanceof Config) {
            if (!$nameserver->nameservers) {
                throw new \UnderflowException('Empty config with no DNS servers');
            }

            // Hard-coded to check up to 3 DNS servers to match default limits in place in most systems (see MAXNS config).
            // Note to future self: Recursion isn't too hard, but how deep do we really want to go?
            $primary = reset($nameserver->nameservers);
            $secondary = next($nameserver->nameservers);
            $tertiary = next($nameserver->nameservers);

            if ($tertiary !== false) {
                // 3 DNS servers given => nest first with fallback for second and third
                return new CoopExecutor(
                    new RetryExecutor(
                        new FallbackExecutor(
                            $this->createSingleExecutor($primary, $loop),
                            new FallbackExecutor(
                                $this->createSingleExecutor($secondary, $loop),
                                $this->createSingleExecutor($tertiary, $loop)
                            )
                        )
                    )
                );
            } elseif ($secondary !== false) {
                // 2 DNS servers given => fallback from first to second
                return new CoopExecutor(
                    new RetryExecutor(
                        new FallbackExecutor(
                            $this->createSingleExecutor($primary, $loop),
                            $this->createSingleExecutor($secondary, $loop)
                        )
                    )
                );
            } else {
                // 1 DNS server given => use single executor
                $nameserver = $primary;
            }
        }

        return new CoopExecutor(new RetryExecutor($this->createSingleExecutor($nameserver, $loop)));
    }

    /**
     * @param string $nameserver
     * @param LoopInterface $loop
     * @return ExecutorInterface
     * @throws \InvalidArgumentException for invalid DNS server address
     */
    private function createSingleExecutor($nameserver, LoopInterface $loop)
    {
        $parts = \parse_url($nameserver);

        if (isset($parts['scheme']) && $parts['scheme'] === 'tcp') {
            $executor = $this->createTcpExecutor($nameserver, $loop);
        } elseif (isset($parts['scheme']) && $parts['scheme'] === 'udp') {
            $executor = $this->createUdpExecutor($nameserver, $loop);
        } else {
            $executor = new SelectiveTransportExecutor(
                $this->createUdpExecutor($nameserver, $loop),
                $this->createTcpExecutor($nameserver, $loop)
            );
        }

        return $executor;
    }

    /**
     * @param string $nameserver
     * @param LoopInterface $loop
     * @return TimeoutExecutor
     * @throws \InvalidArgumentException for invalid DNS server address
     */
    private function createTcpExecutor($nameserver, LoopInterface $loop)
    {
        return new TimeoutExecutor(
            new TcpTransportExecutor($nameserver, $loop),
            5.0,
            $loop
        );
    }

    /**
     * @param string $nameserver
     * @param LoopInterface $loop
     * @return TimeoutExecutor
     * @throws \InvalidArgumentException for invalid DNS server address
     */
    private function createUdpExecutor($nameserver, LoopInterface $loop)
    {
        return new TimeoutExecutor(
            new UdpTransportExecutor(
                $nameserver,
                $loop
            ),
            5.0,
            $loop
        );
    }
}
