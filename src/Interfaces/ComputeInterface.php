<?php

namespace CloudDoctor\Interfaces;

use CloudDoctor\Common\ComputeGroup;
use phpseclib\Net\SFTP;

interface ComputeInterface
{
    public function deploy();

    public function exists(): bool;

    public function destroy(): bool;
    
    public function isTransitioning(): bool;

    public function isRunning(): bool;

    public function isStopped(): bool;

    public function getIp(): ?string;

    public function getComputeGroup(): ComputeGroup;

    public function updateMetaData() : void;
}
