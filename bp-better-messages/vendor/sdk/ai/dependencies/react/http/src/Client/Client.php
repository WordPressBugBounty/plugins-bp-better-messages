<?php

namespace WordPlusSDK\React\Http\Client;

use WordPlusSDK\Psr\Http\Message\RequestInterface;
use WordPlusSDK\React\Http\Io\ClientConnectionManager;
use WordPlusSDK\React\Http\Io\ClientRequestStream;

/**
 * @internal
 */
class Client
{
    /** @var ClientConnectionManager */
    private $connectionManager;

    public function __construct(ClientConnectionManager $connectionManager)
    {
        $this->connectionManager = $connectionManager;
    }

    /** @return ClientRequestStream */
    public function request(RequestInterface $request)
    {
        return new ClientRequestStream($this->connectionManager, $request);
    }
}
