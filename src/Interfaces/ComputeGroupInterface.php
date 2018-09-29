<?php

namespace CloudDoctor\Interfaces;

use CloudDoctor\Common\ComputeGroup;
use phpseclib\Net\SFTP;

interface ComputeGroupInterface
{
    public function deploy(): void;

    public function isRunning(): bool;

    public function countComputes(): int;
    
    public function getScale(): int;
    
    public function isScalingRequired(): int;
}
