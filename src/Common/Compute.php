<?php

namespace CloudDoctor\Common;

use CloudDoctor\CloudDoctor;
use CloudDoctor\Exceptions\CloudDoctorException;
use CloudDoctor\Interfaces\ComputeInterface;
use CloudDoctor\Interfaces\RequestInterface;
use phpseclib\Net\SFTP;
use phpseclib\Net\SSH2;

abstract class Compute extends Entity implements ComputeInterface
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
    /** @var string[] */
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

    /** @var SSH2 */
    private $sshConnection;

    protected $config;

    public function __construct(ComputeGroup $computeGroup, $config = null)
    {
        $this->computeGroup = $computeGroup;
        $this->config = $config;

        if ($config) {
            $this->setType($config['type']);
            $this->setNameFormat($config['name']);
            $this->setRegion($config['region']);
            if (isset($config['username'])) {
                $this->setUsername($config['username']);
            }
            if (isset($config['tags'])) {
                foreach ($config['tags'] as $key => $tag) {
                    if(is_string($key)){
                        $this->addTag($tag, $key);
                    }
                }
            }
        }
    }

    /**
     * @param string $tag
     * @return Compute
     */
    public function addTag(string $tag, $key = null): ComputeInterface
    {
        if($key){
            $this->tags[$key] = $tag;
        }else {
            $this->tags[] = $tag;
        }
        $this->tags = array_unique($this->tags);
        return $this;
    }

    public static function Factory(ComputeGroup $computeGroup = null, $config = null): ComputeInterface
    {
        return new Compute($computeGroup, $config);
    }

    public function getSshConnection(): ?SFTP
    {
        if ($this->sshConnection instanceof SSH2 && $this->sshConnection->isConnected()) {
            return $this->sshConnection;
        }
        $publicIp = $this->getPublicIp();
        if ($publicIp) {
            for ($attempt=0; $attempt < 30; $attempt++) {
                foreach ($this->getComputeGroup()->getSsh()['port'] as $port) {
                    $fsock = @fsockopen($publicIp, $port, $errno, $errstr, 3);
                    if ($fsock) {
                        $ssh = new SFTP($fsock);
                        #\Kint::dump(CloudDoctor::$privateKeys);
                        foreach (CloudDoctor::$privateKeys as $privateKey) {
                            $key = new RSA();
                            $key->loadKey($privateKey);
                            #CloudDoctor::Monolog()->addDebug("    > Logging in to {$publicIp} on port {$port} as '{$this->getUsername()}' with key ...");
                            if ($ssh->login($this->getUsername(), $key)) {
                                #CloudDoctor::Monolog()->addDebug("     > Logging in [OKAY]");
                                $this->sshConnection = $ssh;
                                return $this->sshConnection;
                            } else {
                                #CloudDoctor::Monolog()->addDebug("     > Logging in [FAIL]");
                            }
                        }
                    }
                }
            }
            return null;
        } else {
            return null;
        }
    }

    public function isValid(): bool
    {
        $this->validityReasons = [];
        $this->testValidity();
        return count($this->validityReasons) == 0;
    }
    
    protected function testValidity() : void
    {
        if (strlen($this->getName()) < 3) {
            $this->validityReasons[] = sprintf("Name '%s' is too short! Minimum is %d, length was %d.", $this->getName(), 3, strlen($this->getName()));
        }
        if (strlen($this->getName()) > 32) {
            $this->validityReasons[] = sprintf("Name '%s' is too long! Maximum is %d, length was %d.", $this->getName(), 32, strlen($this->getName()));
        }
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
        CloudDoctor::Monolog()->addNotice("        │├┬ {$this->getName()} Running '{$command}':");
        $response = $this->sshRun($command);
        if (!empty(trim($response))) {
            $lines = explode("\n", $response);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    CloudDoctor::Monolog()->addNotice("        ││└ {$line}");
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

    public function sshRun(string $command, int $maxAttempts = 3): string
    {
        $timeoutSeconds = 20;
        $attempts = 0;
        $start = microtime(true);
        $connected = false;
        while (!$connected) {
            $connection = $this->getSshConnection();
            if ($connection != null) {
                $connected = $connection->isConnected();
            } else {
                sleep(0.5);
                if (microtime(true) - $start > $timeoutSeconds) {
                    throw new CloudDoctorException("Failure to run SSH command on '{$this->getName()}': {$command}");
                }
            }
        }

        $start = microtime(true);
        while ($attempts < $maxAttempts) {
            $attempts++;
            if ($connection instanceof SSH2) {
                return trim($connection->exec(" " . $command));
            }
            if ($attempts == $maxAttempts && microtime(true) - $start > $timeoutSeconds) {
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
    public function setComputeGroup(ComputeGroup $computeGroup): ComputeInterface
    {
        $this->computeGroup = $computeGroup;
        return $this;
    }

    protected function recalculateHostname(): ComputeInterface
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
    public function setHostNameFormat(string $hostNameFormat): ComputeInterface
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
    public function setGroupIndex(int $groupIndex): ComputeInterface
    {
        $this->groupIndex = $groupIndex;
        if (isset($this->nameFormat)) {
            $this->recalculateName();
        }
        return $this;
    }

    protected function recalculateName(): ComputeInterface
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
    public function setNameFormat(string $nameFormat): ComputeInterface
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
        #$ssh->disconnect();
        return true;
    }

    public function sshOkayWait() : void
    {
        #echo "Waiting for SSH to come up...";
        while (!$this->sshOkay()) {
            // Wait for SSH to come up...
            sleep(0.5);
            #echo ".";
        }
        #echo "\n";
    }

    /**
     * @return string[]
     */
    public function getRegion(): array
    {
        return $this->region;
    }

    /**
     * @param string[] $region
     * @return Compute
     */
    public function setRegion(array $region): ComputeInterface
    {
        $this->region = $region;
        return $this;
    }

    /**
     * @param string $region
     * @return Compute
     */
    public function addRegion(string $region): ComputeInterface
    {
        $this->region[] = $region;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getType(): array
    {
        return $this->type;
    }

    /**
     * @param string[] $type
     * @return Compute
     */
    public function setType(array $type): ComputeInterface
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @param string $type
     * @return Compute
     */
    public function addType(string $type): ComputeInterface
    {
        $this->type[] = $type;
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
    public function setTags(array $tags): ComputeInterface
    {
        $this->tags = $tags;
        return $this;
    }

    /**
     * @return Request
     */
    public function getRequester(): RequestInterface
    {
        return $this->requester;
    }

    /**
     * @param Request $requester
     * @return ComputeInterface
     */
    public function setRequester(RequestInterface $requester): ComputeInterface
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
    public function setAuthorizedKeys(array $authorizedKeys): ComputeInterface
    {
        $this->authorizedKeys = $authorizedKeys;
        return $this;
    }

    public function addAuthorizedKey(string $authorizedKey): ComputeInterface
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
    public function setUsername(string $username): ComputeInterface
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
