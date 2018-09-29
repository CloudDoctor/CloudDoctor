<?php

namespace CloudDoctor\Common;

use CloudDoctor\CloudDoctor;
use CloudDoctor\Interfaces\ComputeInterface;
use Symfony\Component\Yaml\Yaml;

class Swarmifier
{
    /** @var ComputeInterface[] Da. We put the workers before the managers. */
    protected $workers;
    /** @var ComputeInterface[] */
    protected $managers;

    protected $swarmCredentials;

    /**
     * Swarmifier constructor.
     * @param ComputeInterface[] $managers
     * @param ComputeInterface[] $workers
     */
    public function __construct(array $managers = null, array $workers = null)
    {
        if ($managers) {
            shuffle($managers);
        }
        if ($workers) {
            shuffle($workers);
        }
        $this->managers = $managers;
        $this->workers = $workers;
        if (file_exists("config/swarm-tokens.yml")) {
            $this->swarmCredentials = Yaml::parseFile("config/swarm-tokens.yml");
        }
    }

    public function swarmify()
    {
        CloudDoctor::Monolog()->addDebug("        ├┬ Building Swarm...");
        $this->swarmifyManagers();
        $this->swarmifyWorkers();
        $this->cleanupSwarm();
        CloudDoctor::Monolog()->addDebug("        │");
    }

    protected function swarmifyManagers()
    {
        if ($this->getManagers()) {
            foreach ($this->getManagers() as $node) {
                $this->prep($node, 'manager');
            }
        }
    }

    /**
     * @return ComputeInterface[]
     */
    public function getManagers(): ?array
    {
        return $this->managers;
    }

    /**
     * @param ComputeInterface[] $managers
     * @return Swarmifier
     */
    public function setManagers(array $managers): Swarmifier
    {
        $this->managers = $managers;
        return $this;
    }

    protected function prep(ComputeInterface $compute, string $type)
    {
        CloudDoctor::Monolog()->addDebug("        │├┬ Buzzing up {$compute->getName()} ( {$compute->getHostName()} )...");

        if ($type == 'manager') {
            if (!$this->swarmCredentials || $this->swarmCredentials['ClusterId'] != '') {
                $compute->sshRun("docker swarm leave -f");
                $compute->sshRun("docker swarm init --force-new-cluster");
                $this->swarmCredentials['ClusterId'] = $compute->sshRun('docker info 2>/dev/null | grep ClusterID | awk \'{$1=$1};1\' | cut -d \' \' -f2');
                $this->makeJoinToken($compute, 'worker');
                $this->makeJoinToken($compute, 'manager');
                return;
            } else {
                $clusterId = $compute->sshRun('docker info 2>/dev/null | grep ClusterID | awk \'{$1=$1};1\' | cut -d \' \' -f2');
                if ($clusterId != $this->swarmCredentials['ClusterId'] || $clusterId == '') {
                    if ($clusterId == '') {
                        CloudDoctor::Monolog()->addDebug("        ││└┬ Docker Cluster ID '{$clusterId}' is empty, reasserting...");
                    } else {
                        CloudDoctor::Monolog()->addDebug("        ││└┬ Docker Cluster ID '{$clusterId}' does not match expected Cluster ID '{$this->swarmCredentials['ClusterId']}', reasserting...");
                    }
                    $compute->sshRun("docker swarm leave -f");
                    $compute->sshRun($this->swarmCredentials[$type]);
                    CloudDoctor::Monolog()->addDebug("        ││ └ DONE!");
                } else {
                    CloudDoctor::Monolog()->addDebug("        ││└ Docker Cluster ID '{$clusterId}' matches, nothing to do...");
                }
            }
        } else {
            $clusterId = $compute->sshRun('cat .clusterid');
            if ($clusterId != $this->swarmCredentials['ClusterId'] || $clusterId == '') {
                if ($clusterId == '') {
                    CloudDoctor::Monolog()->addDebug("        ││└┬ Docker Cluster ID '{$clusterId}' is empty, reasserting...");
                } else {
                    CloudDoctor::Monolog()->addDebug("        ││└┬ Docker Cluster ID '{$clusterId}' does not match expected Cluster ID '{$this->swarmCredentials['ClusterId']}', reasserting...");
                }
                $compute->sshRun("docker swarm leave -f");
                $compute->sshRun($this->swarmCredentials[$type]);
                $compute->sshRun("echo \"{$this->swarmCredentials['ClusterId']}\" > .clusterid");
                CloudDoctor::Monolog()->addDebug("        ││ └ DONE!");
            } else {
                CloudDoctor::Monolog()->addDebug("        ││└ Docker Cluster ID '{$clusterId}' matches, nothing to do...");
            }
        }
    }

    protected function makeJoinToken(ComputeInterface $compute, string $type)
    {
        $output = $compute->sshRunDebug("docker swarm join-token {$type}");
        $output = explode("\n", $output);
        $output = array_filter($output);
        $output = array_values($output);
        $this->swarmCredentials[$type] = trim($output[1]);
        file_put_contents("config/swarm-tokens.yml", Yaml::dump($this->swarmCredentials));
    }

    protected function swarmifyWorkers()
    {
        if ($this->getWorkers()) {
            foreach ($this->getWorkers() as $node) {
                $this->prep($node, 'worker');
            }
        }
    }

    protected function cleanupSwarm()
    {
        if ($this->getManagers()) {
            $managers = $this->getManagers();
            $manager = $managers[array_rand($managers, 1)];
            $manager->sshRun('docker node rm $(docker node ls | tr -s \' \' | cut -d \' \' -f1,3 | grep \'Down\' | cut -d \' \' -f1)');
        }
    }

    /**
     * @return ComputeInterface[]
     */
    public function getWorkers(): ?array
    {
        return $this->workers;
    }

    /**
     * @param ComputeInterface[] $workers
     * @return Swarmifier
     */
    public function setWorkers(array $workers): Swarmifier
    {
        $this->workers = $workers;
        return $this;
    }
}
