<?php

namespace CloudDoctor\Interfaces;

interface ComputeGroupInterface
{
    public function deploy(): void;

    public function isRunning(): bool;

    public function countComputes(): int;
    
    public function getScale(): int;
    
    public function isScalingRequired(): int;
}
