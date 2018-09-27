<?php

namespace CloudDoctor\Linode;

use CloudDoctor\CloudDoctor;
use CloudDoctor\Common\ComputeGroup;
use CloudDoctor\Exceptions\CloudDoctorException;
use CloudDoctor\Interfaces\ComputeInterface;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;
use phpseclib\Net\SSH2;


class Compute extends \CloudDoctor\Common\Compute implements ComputeInterface
{
    /** @var string */
    protected $group;
    /** @var bool */
    protected $backupsEnabled = false;
    /** @var string */
    protected $image;
    /** @var bool */
    protected $privateIpEnabled = true;
    /** @var int */
    protected $swapSize = 512;
    /** @var bool */
    protected $immediateBoot = true;

    /** @var int */
    private $linodeId;

    /** @var string[] */
    private $validityReasons;

    /** @var SSH2 */
    private $sshConnection;

    public function __construct(ComputeGroup $computeGroup, $config = null)
    {
        parent::__construct($computeGroup, $config);
        if ($config) {
            $this->setImage($config['image']);
            if (isset($config['group']))
                $this->setGroup($config['group']);
            if (isset($config['backups_enabled']))
                $this->setBackupsEnabled($config['backups_enabled']);
            if (isset($config['swap']))
                $this->setSwapSize($config['swap']);
            if (isset($config['private_ip']))
                $this->setPrivateIpEnabled($config['private_ip']);
        }
    }

    public function deploy()
    {
        CloudDoctor::Monolog()->addDebug("        ││└ Spinning up on Linode: {$this->getName()}...");
        if (!$this->isValid()) {
            CloudDoctor::Monolog()->addDebug("    Cannot be provisioned because:");
            foreach ($this->validityReasons as $reason) {
                CloudDoctor::Monolog()->addDebug("     - {$reason}");
            }
        } else {
            $response = $this->requester->postJson('/linode/instances', $this->generateLinodeInstanceExpression());
            $this->linodeId = $response->id;
        }
    }

    public function isValid(): bool
    {
        $this->validityReasons = [];
        if (strlen($this->getName()) < 3) {
            $this->validityReasons[] = sprintf("Name '%s' is too short! Minimum is %d, length was %d.", $this->getName(), 3, strlen($this->getName()));
        }
        if (strlen($this->getName()) > 32) {
            $this->validityReasons[] = sprintf("Name '%s' is too long! Maximum is %d, length was %d.", $this->getName(), 32, strlen($this->getName()));
        }
        if (in_array($this->getName(), LinodeInstances::listAvailable())) {
            $this->validityReasons[] = sprintf("Name '%s' is already in use!", $this->getName());
        }
        if (!in_array($this->getRegion(), Regions::listAvailable())) {
            $this->validityReasons[] = sprintf("Region '%s' isn't in '%s'.", $this->getRegion(), implode("|", Regions::listAvailable()));
        }
        if (!in_array($this->getType(), Types::listAvailable())) {
            $this->validityReasons[] = sprintf("Type '%s' isn't in '%s'.", $this->getType(), implode("|", Types::listAvailable()));
        }
        if (!in_array($this->getImage(), Images::listAvailable())) {
            $this->validityReasons[] = sprintf("Type '%s' isn't in '%s'.", $this->getImage(), implode("|", Images::listAvailable()));
        }
        return count($this->validityReasons) == 0;
    }

    /**
     * @return string
     */
    public function getImage(): string
    {
        return $this->image;
    }

    /**
     * @param string $image
     * @return Compute
     */
    public function setImage(string $image): Compute
    {
        $this->image = $image;
        return $this;
    }

    public function generateLinodeInstanceExpression(): array
    {
        $linode = [
            "backups_enabled" => $this->isBackupsEnabled(),
            "swap_size" => $this->getSwapSize(),
            "type" => Types::LabelToId($this->getType()),
            "region" => $this->getRegion(),
            "image" => Images::LabelToId($this->getImage()),
            "root_pass" => CloudDoctor::generatePassword(),
            "authorized_keys" => $this->getAuthorizedKeys(),
            "booted" => $this->isImmediateBoot(),
            "label" => substr($this->getName(), 0, 32),
            "tags" => $this->getTags(),
            "group" => $this->getGroup(),
            "private_ip" => $this->isPrivateIpEnabled(),
        ];
        return $linode;
    }

    /**
     * @return bool
     */
    public function isBackupsEnabled(): bool
    {
        return $this->backupsEnabled;
    }

    /**
     * @param bool $backupsEnabled
     * @return Compute
     */
    public function setBackupsEnabled(bool $backupsEnabled): Compute
    {
        $this->backupsEnabled = $backupsEnabled;
        return $this;
    }

    /**
     * @return int
     */
    public function getSwapSize(): int
    {
        return $this->swapSize;
    }

    /**
     * @param int $swapSize
     * @return Compute
     */
    public function setSwapSize(int $swapSize): Compute
    {
        $this->swapSize = $swapSize;
        return $this;
    }

    /**
     * @return bool
     */
    public function isImmediateBoot(): bool
    {
        return $this->immediateBoot;
    }

    /**
     * @param bool $immediateBoot
     * @return Compute
     */
    public function setImmediateBoot(bool $immediateBoot): Compute
    {
        $this->immediateBoot = $immediateBoot;
        return $this;
    }

    /**
     * @return string
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * @param string $group
     * @return Compute
     */
    public function setGroup(string $group): Compute
    {
        $this->group = $group;
        return $this;
    }

    /**
     * @return bool
     */
    public function isPrivateIpEnabled(): bool
    {
        return $this->privateIpEnabled;
    }

    /**
     * @param bool $privateIpEnabled
     * @return Compute
     */
    public function setPrivateIpEnabled(bool $privateIpEnabled): Compute
    {
        $this->privateIpEnabled = $privateIpEnabled;
        return $this;
    }

    public function exists(): bool
    {
        return in_array($this->getName(), LinodeInstances::listAvailable());
    }

    public function destroy()
    {
        $this->requester->deleteJson("/linode/instances/{$this->getLinodeId()}");
        $this->linodeId = null;
    }

    private function getLinodeId(): int
    {
        if (!$this->linodeId) {
            $this->linodeId = array_search($this->getName(), LinodeInstances::listAvailable());
        }
        return $this->linodeId;
    }

    public function isTransitioning(): bool
    {
        $state = $this->getLinodeState();
        switch ($state) {
            case 'booting':
            case 'rebooting':
            case 'shutting_down':
            case 'deleting':
            case 'provisioning':
            case 'migrating':
            case 'rebuilding':
            case 'cloning':
            case 'restoring':
                return true;
            case 'running':
            case 'offline':
                return false;
            default:
                throw new CloudDoctorException("Not sure if '{$state}' status is transitioning between states or not!");
        }
    }

    protected function getLinodeState(): string
    {
        $linode = LinodeInstances::GetById($this->getLinodeId());
        return $linode->status;
    }

    public function isRunning(): bool
    {
        return $this->getLinodeState() == 'running';
    }

    public function isStopped(): bool
    {
        return $this->getLinodeState() == 'offline';
    }

    public function getSshConnection(): ?SFTP
    {
        if($this->sshConnection instanceof SSH2 && $this->sshConnection->isConnected()){
            return $this->sshConnection;
        }
        $publicIp = $this->getPublicIp();
        if ($publicIp) {
            foreach ($this->getComputeGroup()->getSsh()['port'] as $port) {
                $fsock = @fsockopen($publicIp, $port, $errno, $errstr, 120);
                if ($fsock) {
                    $ssh = new SFTP($fsock);
                    foreach (CloudDoctor::$privateKeys as $privateKey) {
                        $key = new RSA();
                        $key->loadKey($privateKey);
                        #CloudDoctor::Monolog()->addDebug("    > Logging in to {$publicIp}:{$port} as '{$this->getUsername()}' with key ...";
                        if ($ssh->login($this->getUsername(), $key)) {
                            #CloudDoctor::Monolog()->addDebug(" [OKAY]");
                            $this->sshConnection = $ssh;
                            return $this->sshConnection;
                        } else {
                            #CloudDoctor::Monolog()->addDebug(" [FAIL]");
                        }
                    }
                }
            }
            return null;
        } else {
            return null;
        }
    }

    public function getPublicIp(): string
    {
        $linode = LinodeInstances::GetById($this->getLinodeId());
        $publicIp = null;
        foreach ($linode->ipv4 as $ip) {
            if (!$this->isIpPrivate($ip)) {
                $publicIp = $ip;
            }
        }
        return $publicIp;
    }

    /**
     * @return mixed
     */
    public function getValidityReasons()
    {
        return $this->validityReasons;
    }

    /**
     * @param mixed $validityReasons
     * @return Compute
     */
    public function setValidityReasons($validityReasons)
    {
        $this->validityReasons = $validityReasons;
        return $this;
    }
}