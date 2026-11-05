<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

readonly class SendResult
{
    /**
     * @param  list<string>  $gone  Connections that no longer exist
     * @param  list<string>  $failed  Connections that could not be reached for another reason
     */
    public function __construct(
        public int $total,
        public array $gone = [],
        public array $failed = [],
    ) {}
}
