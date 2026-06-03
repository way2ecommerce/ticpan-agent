<?php

namespace W2e\Ticpan\Cron;

use W2e\Ticpan\Model\Agent;

class SendReport
{
    /** @var Agent */
    private $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function execute(): void
    {
        $this->agent->run();
    }
}
