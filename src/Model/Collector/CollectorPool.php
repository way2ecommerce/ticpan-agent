<?php

namespace W2e\Ticpan\Model\Collector;

class CollectorPool
{
    /** @param CollectorInterface[] $collectors */
    public function __construct(private readonly array $collectors = []) {}

    /** @return CollectorInterface[] */
    public function getCollectors(): array
    {
        return $this->collectors;
    }
}
