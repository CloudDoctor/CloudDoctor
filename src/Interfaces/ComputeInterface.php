<?php

namespace CloudDoctor\Interfaces;

use CloudDoctor\Common\ComputeGroup;
use phpseclib\Net\SFTP;

interface ComputeInterface
{
    public function deploy();

    public function exists(): bool;

    public function destroy(): bool;

    public function sshOkay(): bool;

    public function sshRun(string $command): string;

    public function sshDownloadFile(string $remoteFile, string $localFile): bool;

    public function sshUploadFile(string $localFile, string $remoteFile): bool;

    public function getSshConnection(): ?SFTP;

    public function isTransitioning(): bool;

    public function isRunning(): bool;

    public function isStopped(): bool;

    public function getName(): string;

    public function getHostName(): string;

    public function getHostNames(): array;

    public function getCNames(): array;

    public function getPublicIp(): string;

    public function getComputeGroup(): ComputeGroup;
}