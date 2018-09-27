<?php

namespace CloudDoctor\Linode;

use CloudDoctor\CloudDoctor;
use Monolog\Logger;

class DNSController extends LinodeEntity
{
    const ENDPOINT = '/domains';

    private $verifyRecordCache;

    private $linodeDomainCache;

    public function verifyRecordCorrect(string $domain, array $values) : bool
    {
        $linodeDomain = $this->getLinodeDomain($domain);
        if(!isset($this->verifyRecordCache[$linodeDomain->id])){
            $this->verifyRecordCache[$linodeDomain->id] = self::getHttpRequest()->getJson(self::ENDPOINT . "/{$linodeDomain->id}/records");
        }
        $allSubdomains = $this->verifyRecordCache[$linodeDomain->id];

        $foundValues = [];
        foreach($allSubdomains->data as $subdomain){
            #\Kint::dump($subdomain->name . "." . $linodeDomain->domain, $domain);
            if (trim($subdomain->name . "." . $linodeDomain->domain, ".") == $domain) {
                $foundValues[] = $subdomain->target;
            }
        }
        sort($foundValues);
        sort($values);

        return count(array_diff($values, $foundValues)) == 0 && count($values) == count($foundValues);
    }

    public function removeMatchingDomains(string $type, string $domain)
    {
        $linodeDomain = $this->getLinodeDomain($domain);
        $allSubdomains = self::getHttpRequest()->getJson(self::ENDPOINT . "/{$linodeDomain->id}/records");
        $linodeDomainIdsToPurge = [];
        foreach ($allSubdomains->data as $potentialMatch) {
            if (trim($potentialMatch->name . "." . $linodeDomain->domain,".") == $domain && $potentialMatch->type == strtoupper($type)) {
                $linodeDomainIdsToPurge[] = $potentialMatch->id;
            }
        }
        $count = 0;
        foreach ($linodeDomainIdsToPurge as $purgeId) {
            self::getHttpRequest()->deleteJson(self::ENDPOINT . "/{$linodeDomain->id}/records/{$purgeId}");
            $count++;
        }
        if($count > 0){
            CloudDoctor::Monolog()->debug("         │├ Purging {$domain} {$type} record... [{$count} REMOVED]");
        }else{
            CloudDoctor::Monolog()->emerg("    │├ Purging {$domain} {$type} record... [{$count} REMOVED]");
        }
    }

    private function getLinodeDomain($domain): ?\StdClass
    {
        if(isset($this->linodeDomainCache[$domain])){
            return $this->linodeDomainCache[$domain];
        }
        $zones = DNSController::describeAvailable();
        $domainFragments = explode(".", trim($domain, '.'));
        $domainFragments = array_reverse($domainFragments);
        $stub = '';
        foreach ($domainFragments as $domainFragment) {
            $stub = trim($domainFragment . "." . $stub, ".");
            foreach ($zones as $zone) {
                if ($zone->domain == $stub) {
                    $this->linodeDomainCache[$domain] = $zone;
                    return $this->linodeDomainCache[$domain];
                }
            }
        }
        return null;
    }

    public function createRecord(string $type, string $domain, string $value): ?int
    {
        $linodeDomain = $this->getLinodeDomain($domain);
        if ($linodeDomain) {
            $domainRecord = self::getHttpRequest()->postJson(self::ENDPOINT . "/{$linodeDomain->id}/records", [
                'type' => strtoupper($type),
                'target' => $value,
                'name' => $domain,
                'ttl_sec' => 300,
            ]);
            CloudDoctor::Monolog()->addDebug("        │├  Creating {$domain} => {$value} {$type} record SUCCESSFUL");
            return $domainRecord->id;
        } else {
            CloudDoctor::Monolog()->addEmergency("        │├  Creating {$domain} => {$value} {$type} record FAILURE");
            return null;
        }
    }
}