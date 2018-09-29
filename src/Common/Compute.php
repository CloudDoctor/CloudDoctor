<?php

namespace CloudDoctor\Common;

use CloudDoctor\CloudDoctor;
use CloudDoctor\Exceptions\CloudDoctorException;
use phpseclib\Net\SFTP;
use phpseclib\Net\SSH2;

class Compute extends Entity
{
    /** @var string */
    protected $nameFormat;
    /** @var string */
    protected $name;
    /** @var string */
    protected $hostNameFormat;
    /** @var string */
    protected $hostName;
    /** @var integer */
    protected $groupIndex;
    /** @var string */
    protected $region;
    /** @var string */
    protected $type;
    /** @var string[] */
    protected $tags;
    /** @var Request */
    protected $requester;
    /** @var string[] */
    protected $authorizedKeys;
    /** @var string */
    protected $username = 'root';

    /** @var ComputeGroup */
    protected $computeGroup;

    public function __construct(ComputeGroup $computeGroup, $config = null)
    {
        $this->computeGroup = $computeGroup;

        if ($config) {
            $this->setType($config['type']);
            $this->setNameFormat($config['name']);
            $this->setRegion($config['region']);
            if (isset($config['username'])) {
                $this->setUsername($config['username']);
            }
            if (isset($config['tags'])) {
                foreach ($config['tags'] as $tag) {
                    $this->addTag($tag);
                }
            }
        }
    }

    /**
     * @param string $tag
     * @return Compute
     */
    public function addTag(string $tag): Compute
    {
        $this->tags[] = $tag;
        $this->tags = array_unique($this->tags);
        return $this;
    }

    public static function Factory(ComputeGroup $computeGroup = null, $config = null): Compute
    {
        return new Compute($computeGroup, $config);
    }

    public function sshDownloadFile(string $remotePath, string $localPath): bool
    {
        $connection = $this->getSshConnection();
        if ($connection instanceof SFTP) {
            return $connection->get($remotePath, $localPath) === true;
        }
        return false;
    }

    public function sshUploadFile(string $localPath, string $remotePath): bool
    {
        $connection = $this->getSshConnection();
        if ($connection instanceof SFTP) {
            return $connection->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE) === true;
        }
        return false;
    }

    public function sshRunDebug(string $command): string
    {
        CloudDoctor::Monolog()->addDebug("        │├┬ {$this->getName()} Running '{$command}':");
        $response = $this->sshRun($command);
        if (!empty(trim($response))) {
            $lines = explode("\n", $response);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    CloudDoctor::Monolog()->addDebug("        ││└ {$line}");
                }
            }
        }

        return $response;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    public function sshRun(string $command): string
    {
        $timeoutSeconds = 60;
        $start = microtime(true);
        $connected = false;
        while (!$connected) {
            $connection = $this->getSshConnection();
            if ($connection instanceof SSH2) {
                return trim($connection->exec(" " . $command));
            }
            sleep(0.5);
            if (microtime(true) - $start > $timeoutSeconds) {
                throw new CloudDoctorException("Failure to run SSH command on '{$this->getName()}': {$command}");
            }
        }
    }

    public function getHostName(): string
    {
        if ($this->getComputeGroup()->hasDns()) {
            $dns = $this->getComputeGroup()->getDns();
            if (isset($dns['a'])) {
                $this->setHostNameFormat($dns['a'][0]);
            } elseif (isset($dns['cname'])) {
                $this->setHostNameFormat($dns['cname'][0]);
            } else {
                return $this->getName();
            }
            $this->recalculateHostname();
            return $this->hostName;
        } else {
            return $this->getName();
        }
    }

    /**
     * @return ComputeGroup
     */
    public function getComputeGroup(): ComputeGroup
    {
        return $this->computeGroup;
    }

    /**
     * @param ComputeGroup $computeGroup
     * @return Compute
     */
    public function setComputeGroup(ComputeGroup $computeGroup): Compute
    {
        $this->computeGroup = $computeGroup;
        return $this;
    }

    protected function recalculateHostname(): Compute
    {
        $this->hostName = sprintf($this->getHostNameFormat(), $this->getGroupIndex());
        return $this;
    }

    /**
     * @return string
     */
    public function getHostNameFormat(): string
    {
        return $this->hostNameFormat;
    }

    /**
     * @param string $hostNameFormat
     * @return Compute
     */
    public function setHostNameFormat(string $hostNameFormat): Compute
    {
        $this->hostNameFormat = $hostNameFormat;
        return $this;
    }

    /**
     * @return int
     */
    public function getGroupIndex(): int
    {
        return $this->groupIndex;
    }

    /**
     * @param int $groupIndex
     * @return Compute
     */
    public function setGroupIndex(int $groupIndex): Compute
    {
        $this->groupIndex = $groupIndex;
        if (isset($this->nameFormat)) {
            $this->recalculateName();
        }
        return $this;
    }

    protected function recalculateName(): Compute
    {
        $this->name = sprintf($this->getNameFormat(), $this->getGroupIndex());
        return $this;
    }

    /**
     * @return string
     */
    public function getNameFormat(): string
    {
        return $this->nameFormat;
    }

    /**
     * @param string $nameFormat
     * @return Compute
     */
    public function setNameFormat(string $nameFormat): Compute
    {
        $this->nameFormat = $nameFormat;
        if (isset($this->groupIndex)) {
            $this->recalculateName();
        }
        return $this;
    }

    public function getHostNames(): array
    {
        $hostnames = [];
        if ($this->getComputeGroup()->hasDns()) {
            $dns = $this->getComputeGroup()->getDns();
            if (isset($dns['a'])) {
                foreach ($dns['a'] as $record) {
                    $hostnames[] = sprintf($record, $this->getGroupIndex());
                }
            }
        }
        return $hostnames;
    }

    public function getCNames(): array
    {
        $hostnames = [];
        if ($this->getComputeGroup()->hasDns()) {
            $dns = $this->getComputeGroup()->getDns();
            if (isset($dns['cnames'])) {
                foreach ($dns['cnames'] as $record) {
                    $hostnames[] = sprintf($record, $this->getGroupIndex());
                }
            }
        }
        return $hostnames;
    }

    public function sshOkay(): bool
    {
        $ssh = $this->getSshConnection();
        if (!$ssh) {
            return false;
        }
        $ssh->disconnect();
        return true;
    }

    /**
     * @return string
     */
    public function getRegion(): string
    {
        return $this->region;
    }

    /**
     * @param string $region
     * @return Compute
     */
    public function setRegion(string $region): Compute
    {
        $this->region = $region;
        return $this;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return Compute
     */
    public function setType(string $type): Compute
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param string[] $tags
     * @return Compute
     */
    public function setTags(array $tags): Compute
    {
        $this->tags = $tags;
        return $this;
    }

    /**
     * @return Request
     */
    public function getRequester(): Request
    {
        return $this->requester;
    }

    /**
     * @param Request $requester
     * @return Compute
     */
    public function setRequester(Request $requester): Compute
    {
        $this->requester = $requester;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getAuthorizedKeys(): array
    {
        return $this->authorizedKeys;
    }

    /**
     * @param string[] $authorizedKeys
     * @return Compute
     */
    public function setAuthorizedKeys(array $authorizedKeys): Compute
    {
        $this->authorizedKeys = $authorizedKeys;
        return $this;
    }

    public function addAuthorizedKey(string $authorizedKey): Compute
    {
        $this->authorizedKeys[] = $authorizedKey;
        return $this;
    }

    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @param string $username
     * @return Compute
     */
    public function setUsername(string $username): Compute
    {
        $this->username = $username;
        return $this;
    }

    protected function isIpPrivate($ip): bool
    {
        $pri_addrs = array(
            '10.0.0.0|10.255.255.255', // single class A network
            '172.16.0.0|172.31.255.255', // 16 contiguous class B network
            '192.168.0.0|192.168.255.255', // 256 contiguous class C network
            '169.254.0.0|169.254.255.255', // Link-local address also refered to as Automatic Private IP Addressing
            '127.0.0.0|127.255.255.255' // localhost
        );

        $long_ip = ip2long($ip);
        if ($long_ip != -1) {
            foreach ($pri_addrs as $pri_addr) {
                list ($start, $end) = explode('|', $pri_addr);

                // IF IS PRIVATE
                if ($long_ip >= ip2long($start) && $long_ip <= ip2long($end)) {
                    return true;
                }
            }
        }

        return false;
    }
}
