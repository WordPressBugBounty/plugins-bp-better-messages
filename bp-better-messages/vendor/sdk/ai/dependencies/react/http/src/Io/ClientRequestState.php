<?php

namespace WordPlusSDK\React\Http\Io;

/** @internal */
class ClientRequestState
{
    /** @var int */
    public $numRequests = 0;

    /** @var ?\WordPlusSDK\React\Promise\PromiseInterface */
    public $pending = null;

    /** @var ?\WordPlusSDK\React\EventLoop\TimerInterface */
    public $timeout = null;
}
