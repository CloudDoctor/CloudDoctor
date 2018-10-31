<?php

namespace CloudDoctor\Common;

use CloudDoctor\CloudDoctor;
use CloudDoctor\Interfaces\ComputeInterface;
use CloudDoctor\Interfaces\DNSControllerInterface;
use CloudDoctor\Linode\DNSController;

class DnsEnforcer
{

    /** @var DNSControllerInterface[] */
    protected $dnsControllers;
    /** @var ComputeInterface[] */
    protected $computes;

    public function __construct(array $dnsControllers)
    {
        $this->dnsControllers = $dnsControllers;
    }

    public function addCompute(ComputeInterface $compute): DnsEnforcer
    {
        $this->computes[] = $compute;
        return $this;
    }

    public function enforce(): void
    {
        CloudDoctor::Monolog()->addNotice("        ├┬ Updating DNS:");
        $dnsList = [];
        foreach ($this->getComputes() as $compute) {
            /** @var $compute ComputeInterface */
            foreach ($compute->getHostNames() as $hostName) {
                $dnsList['a'][$hostName][] = $compute->getIp();
            }
            foreach ($compute->getCNames() as $cname) {
                $dnsList['cnames'][$cname][] = $compute->getHostName();
            }
        }

        foreach ($this->dnsControllers as $dnsController) {
            if (isset($dnsList['a']) && count($dnsList['a']) > 0) {
                foreach ($dnsList['a'] as $domain => $ips) {
                    if (!$dnsController->verifyRecordCorrect($domain, $ips)) {
                        $dnsController->removeRecord('a', $domain);
                        foreach ($ips as $ip) {
                            $dnsController->createRecord('a', $domain, $ip);
                        }
                    } else {
                        CloudDoctor::Monolog()->addNotice("        │├ Already Complete: {$domain}");
                    }
                }
            }
            if (isset($dnsList['cnames']) && count($dnsList['cnames']) > 0) {
                foreach ($dnsList['cnames'] as $domain => $values) {
                    if (!$dnsController->verifyRecordCorrect($domain, $values)) {
                        $dnsController->removeRecord('cname', $domain);
                        foreach ($values as $value) {
                            $dnsController->createRecord('cname', $domain, $value);
                        }
                    } else {
                        CloudDoctor::Monolog()->addNotice("        │├ Already Complete: {$domain}");
                    }
                }
            }
        }
        CloudDoctor::Monolog()->addNotice("        │└  Updating DNS Complete");
    }

    /**
     * @return ComputeInterface[]
     */
    public function getComputes(): array
    {
        return $this->computes;
    }

    /**
     * @param ComputeInterface[] $computes
     * @return DnsEnforcer
     */
    public function setComputes(array $computes): DnsEnforcer
    {
        $this->computes = $computes;
        return $this;
    }
}
