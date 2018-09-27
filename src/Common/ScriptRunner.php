<?php

namespace CloudDoctor\Common;

use CloudDoctor\CloudDoctor;

class ScriptRunner
{
    /** @var Compute[] Da. We put the workers before the managers. */
    protected $workers;
    /** @var Compute[] */
    protected $managers;

    /**
     * Swarmifier constructor.
     * @param Compute[] $computes
     * @param Compute[] $workers
     */
    public function __construct(array $computes)
    {
        shuffle($managers);
        shuffle($workers);
        $this->managers = $managers;
        $this->workers = $workers;
    }

    public function swarmify()
    {
        CloudDoctor::Monolog()->addDebug("        ├┬ Building Swarm:");
        $this->swarmifyManagers();
        $this->swarmifyWorkers();
    }

    protected function swarmifyManagers()
    {
        foreach ($this->getManagers() as $node) {
            $this->prep($node);
        }
    }

    /**
     * @return Compute[]
     */
    public function getManagers(): array
    {
        return $this->managers;
    }

    /**
     * @param Compute[] $managers
     * @return Swarmifier
     */
    public function setManagers(array $managers): Swarmifier
    {
        $this->managers = $managers;
        return $this;
    }

    protected function prep(Compute $compute)
    {
        CloudDoctor::Monolog()->addDebug("    > Buzzing up {$compute->getName()}...");
    }

    protected function swarmifyWorkers()
    {
        foreach ($this->getWorkers() as $node) {
            $this->prep($node);
        }
    }

    /**
     * @return Compute[]
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    /**
     * @param Compute[] $workers
     * @return Swarmifier
     */
    public function setWorkers(array $workers): Swarmifier
    {
        $this->workers = $workers;
        return $this;
    }


}