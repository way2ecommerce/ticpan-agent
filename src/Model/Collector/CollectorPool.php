<?php

namespace W2e\Ticpan\Model\Collector;

class CollectorPool
{
    /** @var CollectorInterface[] */
    private $collectors;

    /** @param CollectorInterface[] $collectors */
    public function __construct(array $collectors = [])
    {
        $this->collectors = $collectors;
    }

    /** @return CollectorInterface[] */
    public function getCollectors(): array
    {
        return $this->collectors;
    }
}
