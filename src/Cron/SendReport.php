<?php

namespace W2e\Ticpan\Cron;

use W2e\Ticpan\Model\Agent;

class SendReport
{
    public function __construct(private readonly Agent $agent) {}

    public function execute(): void
    {
        $this->agent->run();
    }
}
