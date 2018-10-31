<?php

namespace CloudDoctor\Interfaces;

interface DNSControllerInterface {
    public function verifyRecordCorrect(string $domain, array $values) : bool;
    public function removeRecord(string $type, string $domain) : int;
    public function createRecord(string $type, string $domain, string $value) : bool;
    public function createRecords(string $type, string $domain, array $values) : bool;
}