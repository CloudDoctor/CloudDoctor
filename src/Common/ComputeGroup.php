<?php

namespace CloudDoctor\Common;

use CloudDoctor\CloudDoctor;
use CloudDoctor\Exceptions\CloudDoctorException;
use CloudDoctor\Exceptions\CloudScriptExecutionException;
use CloudDoctor\Interfaces\ComputeInterface;
use CloudDoctor\Interfaces\RequestInterface;

class ComputeGroup extends Entity
{

    /** @var string */
    protected $role = 'worker';
    /** @var int */
    protected $scale = 0;
    /** @var array */
    protected $scripts = [];
    /** @var array */
    protected $tls = [];
    /** @var array */
    protected $ssh = [];
    /** @var array */
    protected $labels = [];
    /** @var bool */
    protected $experimental = true;
    /** @var string */
    private $groupName;
    /** @var array */
    private $dns = [];
    /** @var array */
    private $tags = [];
    /** @var Compute[] */
    private $compute;
    /** @var CloudDoctor */
    private $cloudDoctor;
    /** @var Request */
    private $request;

    public function __construct(
        CloudDoctor $cloudDoctor,
        $groupName = null,
        $config = null,
        RequestInterface $requester
    ) {
        $this->cloudDoctor = $cloudDoctor;
        $this->request = $requester;
        if ($groupName) {
            $this->setGroupName($groupName);
        }
        if ($config) {
            if (isset($config['role'])) {
                $this->setRole($config['role']);
            }
            if (isset($config['scripts'])) {
                $this->setScripts($config['scripts']);
            }
            if (isset($config['tls'])) {
                $this->setTls($config['tls']);
            }
            if (isset($config['experimental'])) {
                $this->setExperimental($config['experimental']);
            }
            if (isset($config['labels'])) {
                $this->setLabels($config['labels']);
            }
            if (isset($config['ssh'])) {
                $this->setSsh($config['ssh']);
            }
            if (isset($config['dns'])) {
                $this->setDns($config['dns']);
            }
            if (isset($config['tags'])) {
                $this->setTags($config['tags']);
            }
        }
    }

    /**
     * @return array
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array $tags
     * @return ComputeGroup
     */
    public function setTags(array $tags): ComputeGroup
    {
        $this->tags = $tags;
        return $this;
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @param Request $request
     * @return ComputeGroup
     */
    public function setRequest(Request $request): ComputeGroup
    {
        $this->request = $request;
        return $this;
    }

    /**
     * @param array $tls
     * @return ComputeGroup
     */
    public function setTls(array $tls): ComputeGroup
    {
        $this->tls = $tls;
        return $this;
    }

    public static function Factory(CloudDoctor $cloudDoctor, $groupName = null, $config = null, RequestInterface $request): ComputeGroup
    {
        $called = get_called_class();
        return new $called($cloudDoctor, $groupName, $config, $request);
    }

    /**
     * @return array
     */
    public function getDns(): array
    {
        return $this->dns;
    }

    /**
     * @param array $dns
     * @return ComputeGroup
     */
    public function setDns(array $dns): ComputeGroup
    {
        $this->dns = $dns;
        return $this;
    }

    public function hasDns(): bool
    {
        return !empty($this->dns);
    }

    /**
     * @return array
     */
    public function getSsh(): array
    {
        return $this->ssh;
    }

    /**
     * @param array $ssh
     * @return ComputeGroup
     */
    public function setSsh(array $ssh): ComputeGroup
    {
        $this->ssh = $ssh;
        return $this;
    }

    public function getComputeGroupTag(): string
    {
        return "cd.cg=" . crc32($this->getGroupName());
    }

    /**
     * @param Compute $compute
     * @return ComputeGroup
     */
    public function addCompute(Compute $compute)
    {
        $compute->addTag($this->getComputeGroupTag(), 'CloudDoctor_ComputeGroupTag');
        foreach ($this->getTags() as $tag) {
            $compute->addTag($tag);
        }
        $this->compute[] = $compute;
        return $this;
    }

    public function deploy() : void
    {
        CloudDoctor::Monolog()->addNotice("        ├┬ Compute Group '{$this->getGroupName()}':");
        if ($this->getCompute()) {
            foreach ($this->getCompute() as $i => $compute) {
                /** @var $compute ComputeInterface */
                CloudDoctor::Monolog()->addNotice("        │├┬ {$compute->getName()} ( {$compute->getHostName()} ):");
                if (!$compute->exists()) {
                    $compute->deploy();
                    $compute->sshOkayWait();
                } else {
                    CloudDoctor::Monolog()->addNotice("        ││├ {$compute->getName()} already exists ...");
                    if ($compute->isTransitioning()) {
                        CloudDoctor::Monolog()->addNotice("        ││└ {$compute->getName()} is changing state!...");
                    }
                    if ($compute->isRunning()) {
                        CloudDoctor::Monolog()->addNotice("        ││├┬ Testing SSH up...");
                        \Kint::dump($compute->sshOkay());
                        exit;
                        if (!$compute->sshOkay()) {
                            CloudDoctor::Monolog()->addNotice("        │││├ {$compute->getName()} cannot be ssh'd into!...");
                            #die("\n\nStopped execution here\n\n");
                            $compute->destroy();
                            CloudDoctor::Monolog()->addNotice("        │││└ {$compute->getName()} Destroyed!");
                            $compute->deploy();
                            $compute->sshOkayWait();
                        } else {
                            CloudDoctor::Monolog()->addNotice("        │││└ Already Running!");
                            if ($i != count($this->getCompute()) - 1) {
                                CloudDoctor::Monolog()->addNotice("        ││");
                            } else {
                                CloudDoctor::Monolog()->addNotice("        │");
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @return string
     */
    public function getGroupName(): string
    {
        return $this->groupName;
    }

    /**
     * @param string $groupName
     * @return ComputeGroup
     */
    public function setGroupName(string $groupName): ComputeGroup
    {
        $this->groupName = $groupName;
        return $this;
    }

    /**
     * @return null|ComputeInterface[]
     */
    public function getCompute(): ?array
    {
        return $this->compute;
    }

    /**
     * @param Compute[] $compute
     * @return ComputeGroup
     */
    public function setCompute(array $compute): ComputeGroup
    {
        $this->compute = $compute;
        return $this;
    }

    public function waitForRunning(): void
    {
        CloudDoctor::Monolog()->addNotice("        │├┬ Waiting for Compute Group '{$this->getGroupName()}' to be running...");
        while (!$this->isRunning()) {
            sleep(1);
        }
        CloudDoctor::Monolog()->addNotice("        ││└ Done!");
        CloudDoctor::Monolog()->addNotice("        ││");
    }

    public function isRunning(): bool
    {
        $running = true;
        if ($this->getCompute()) {
            foreach ($this->getCompute() as $compute) {
                /** @var ComputeInterface $compute */
                if (!$compute->isRunning()) {
                    $running = false;
                }
            }
        }
        return $running;
    }

    public function runScript(string $name): void
    {
        if (isset($this->scripts[$name])) {
            CloudDoctor::Monolog()->addNotice("        ├┬ Running Scripts: {$name}");

            foreach ($this->scripts[$name] as $s => $script) {
                if ($this->getCompute()) {
                    foreach ($this->getCompute() as $c => $compute) {
                        $skip = false;
                        /** @var ComputeInterface $compute */
                        if (isset($script['skip-if']) && isset($script['skip-if']['command'])) {
                            $skipTestResponse = $compute->sshRun($script['skip-if']['command']);
                            if (isset($script['skip-if']['expect-contains'])) {
                                if (stripos($skipTestResponse, $script['skip-if']['expect-contains']) !== false) {
                                    $skip = true;
                                }
                            }
                            if (isset($script['skip-if']['expect-not-contains'])) {
                                if (stripos($skipTestResponse, $script['skip-if']['expect-not-contains']) === false) {
                                    $skip = true;
                                }
                            }
                        }
                        if (!$skip) {
                            $response = $compute->sshRun($script['command']);
                            if (isset($script['expect-contains'])) {
                                if (stripos($response, $script['expect-contains']) === false) {
                                    throw new CloudScriptExecutionException("Failed to run '{$script['command']}'. Output was:\n{$response}");
                                }
                            }
                            if (isset($script['expect-not-contains'])) {
                                if (stripos($response, $script['expect-not-contains']) !== false) {
                                    throw new CloudScriptExecutionException("Failed to run '{$script['command']}'. Output was:\n{$response}");
                                }
                            }
                            if (!empty(trim($response))) {
                                CloudDoctor::Monolog()->addNotice("        │├┬ {$compute->getName()} Running '{$script['command']}'");
                                $lines = explode("\n", $response);
                                foreach ($lines as $i => $line) {
                                    if ($i == count($lines) - 1) {
                                        CloudDoctor::Monolog()->addNotice("        ││└─ {$line}");
                                    } else {
                                        CloudDoctor::Monolog()->addNotice("        ││├─ {$line}");
                                    }
                                }
                            } else {
                                CloudDoctor::Monolog()->addNotice("        │├─ {$compute->getName()} Running '{$script['command']}'");
                            }
                        } else {
                            CloudDoctor::Monolog()->addNotice("        │├─ {$compute->getName()} Skipping '{$script['command']}'");
                        }
                        CloudDoctor::Monolog()->addNotice("        ││");
                    }
                }
            }
            CloudDoctor::Monolog()->addNotice("        │");
        }
    }

    /**
     * @return string
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * @param string $role
     * @return ComputeGroup
     */
    public function setRole(string $role): ComputeGroup
    {
        if (!in_array($role, ['worker', 'manager'])) {
            throw new CloudDoctorException("Role '{$role}' is not a valid role for Compute Group '{$this->getGroupName()}'.");
        }
        $this->role = $role;
        return $this;
    }

    /**
     * @return array
     */
    public function getScripts(): array
    {
        return $this->scripts;
    }

    /**
     * @param array $scripts
     * @return ComputeGroup
     */
    public function setScripts(array $scripts): ComputeGroup
    {
        $this->scripts = $scripts;
        return $this;
    }

    public function generateTlsCertificates()
    {
        $caPassword = CloudDoctor::generatePassword();
        $ips = "IP:" . ltrim(implode(",IP:", $this->getPublicIps()), ",");
        $computes = $this->getCompute();
        $masterCompute = $computes[array_rand($computes, 1)];

        $this->generateTlsCleanup($masterCompute);

        // Making a CA...
        CloudDoctor::Monolog()->addNotice("        ││├┬ Generating CA certificate");
        $masterCompute->sshRun("openssl genrsa -aes256 -passout pass:{$caPassword} -out ca-key.pem 4096");
        $masterCompute->sshRun("openssl req -new -x509 -passin pass:{$caPassword} -days 365 -key ca-key.pem -sha256 -out ca.pem -subj \"{$this->tls['subject']}\"");
        $masterCompute->sshRun("openssl x509 -outform der -in ca.pem -out ca.crt");
        $masterCompute->sshRun("chmod -v 0444 ca.pem ca.crt");
        $masterCompute->sshRun("chmod -v 0400 ca-key.pem");
        $masterCompute->sshDownloadFile("ca.pem", "config/ca.pem");
        $masterCompute->sshDownloadFile("ca-key.pem", "config/ca-key.pem");
        $masterCompute->sshDownloadFile("ca.crt", "config/ca.crt");
        file_put_contents("config/ca-pass.txt", $caPassword);
        CloudDoctor::Monolog()->addNotice("        │││└ Downloaded ca.pem, ca.crt & ca-key.pem");

        // Making a server cert
        CloudDoctor::Monolog()->addNotice("        ││├┬ Generating server certificate");
        $masterCompute->sshRun("openssl genrsa -out server-key.pem 4096");
        $masterCompute->sshRun("openssl req -subj \"/CN={$this->tls['common-name']}\" -sha256 -new -key server-key.pem -out server.csr");
        $masterCompute->sshRun("echo subjectAltName = DNS:{$this->tls['common-name']},{$ips} > extfile.cnf");
        $masterCompute->sshRun("echo extendedKeyUsage = serverAuth >> extfile.cnf");
        $masterCompute->sshRun("openssl x509 -req -days 365 -sha256 -in server.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -out server-cert.pem -extfile extfile.cnf -passin pass:{$caPassword}");
        $masterCompute->sshRun("chmod -v 0444 server-cert.pem");
        $masterCompute->sshRun("chmod -v 0400 server-key.pem");
        $masterCompute->sshDownloadFile("server-cert.pem", "config/server-cert.pem");
        $masterCompute->sshDownloadFile("server-key.pem", "config/server-key.pem");
        $masterCompute->sshDownloadFile("extfile.cnf", "config/server-extfile.cnf");
        CloudDoctor::Monolog()->addNotice("        │││└ Downloaded server-cert.pem server-key.pem");

        // Now making a client cert
        CloudDoctor::Monolog()->addNotice("        ││├┬ Generating client certificate");
        $masterCompute->sshRun("openssl genrsa -out client-key.pem 4096");
        $masterCompute->sshRun("openssl req -subj '/CN=client' -new -key client-key.pem -out client.csr");
        $masterCompute->sshRun("echo extendedKeyUsage = clientAuth >> extfile.cnf");
        $masterCompute->sshRun("openssl x509 -req -days 365 -sha256 -in client.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -out client-cert.pem -extfile extfile.cnf -passin pass:{$caPassword}");
        $masterCompute->sshRun("chmod -v 0444 client-cert.pem");
        $masterCompute->sshRun("chmod -v 0400 client-key.pem");
        $masterCompute->sshDownloadFile("client-cert.pem", "config/client-cert.pem");
        $masterCompute->sshDownloadFile("client-key.pem", "config/client-key.pem");
        $masterCompute->sshDownloadFile("extfile.cnf", "config/client-extfile.cnf");
        CloudDoctor::Monolog()->addNotice("        │││└ Downloaded client-cert.pem & client-key.pem");

        // Post generation cleanup
        $this->generateTlsCleanup($masterCompute);
    }

    public function downloadCerts() : void
    {
        $computes = $this->getCompute();
        $masterCompute = $computes[array_rand($computes, 1)];
        $masterCompute->sshDownloadFile("/etc/docker/ca.pem", "config/ca.pem");
        $masterCompute->sshDownloadFile("/etc/docker/server-cert.pem", "config/server-cert.pem");
        $masterCompute->sshDownloadFile("/etc/docker/server-key.pem", "config/server-key.pem");
    }

    public function getPublicIps(): array
    {
        $ips = [];
        foreach ($this->getCompute() as $compute) {
            $ips[] = $compute->getPublicIp();
        }
        return $ips;
    }

    public function generateTlsCleanup(ComputeInterface $masterCompute)
    {
        $masterCompute->sshRun("rm -f *.pem *.crt *.srl *.csr extfile.cnf");
    }

    public function setHostNames()
    {
        CloudDoctor::Monolog()->addNotice("        │");
        if ($this->getCompute()) {
            foreach ($this->getCompute() as $compute) {
                CloudDoctor::Monolog()->addNotice("        ├┬ Hostname check: {$compute->getName()}");
                $currentHostname = $compute->sshRun("hostname -f");
                $newHostname = $compute->getHostName();
                CloudDoctor::Monolog()->addNotice("        │├ Should be '{$newHostname}'.");
                if ($currentHostname != $newHostname) {
                    // Set hostname
                    $compute->sshRun("echo \"{$newHostname}\" > /etc/hostname");
                    $compute->sshRun("echo -e \"127.0.0.1\t{$newHostname}\n$(cat /etc/hosts)\" > /etc/hosts");
                    $compute->sshUploadFile("assets/hostname.sh", "hostname.sh");
                    $compute->sshRun("/bin/bash hostname.sh; rm hostname.sh");
                    CloudDoctor::Monolog()->addNotice("        │└ Renamed '{$currentHostname}' to '{$newHostname}'...");
                } else {
                    CloudDoctor::Monolog()->addNotice("        │└ Hostname already correct.");
                }
            }
        }
        CloudDoctor::Monolog()->addNotice("        │");
    }

    public function applyDockerEngineConfig()
    {
        if ($this->getCompute()) {
            foreach ($this->getCompute() as $compute) {
                /** @var ComputeInterface $compute */
                $hosts = [];
                $hosts[] = 'unix:///var/run/docker.sock';
                if ($compute->getComputeGroup()->isTls()) {
                    $hosts[] = 'tcp://' . $compute->getIp() . ":2376";
                }
                $hosts[] = 'tcp://127.0.0.1:2376';

                $daemon = [
                    'experimental' => $this->isExperimental(),
                    'labels' => $this->getLabels(),
                    'hosts' => $hosts,
                ];

                if ($compute->getComputeGroup()->isTls()) {
                    $daemon['tlsverify'] = true;
                    $daemon['tlscacert'] = '/etc/docker/ca.pem';
                    $daemon['tlscert'] = '/etc/docker/server-cert.pem';
                    $daemon['tlskey'] = '/etc/docker/server-key.pem';
                }

                $daemonFilePath = "config/daemon.{$compute->getName()}.json";
                file_put_contents(
                    $daemonFilePath,
                    json_encode($daemon, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
                );
                $compute->sshUploadFile($daemonFilePath, "/etc/docker/daemon.json");
                unlink($daemonFilePath);
                if ($compute->getComputeGroup()->isTls()) {
                    $compute->sshUploadFile("config/server-cert.pem", "/etc/docker/server-cert.pem");
                    $compute->sshUploadFile("config/server-key.pem", "/etc/docker/server-key.pem");
                    $compute->sshUploadFile("config/client-cert.pem", "/etc/docker/client-cert.pem");
                    $compute->sshUploadFile("config/client-key.pem", "/etc/docker/client-key.pem");
                    $compute->sshUploadFile("config/ca.pem", "/etc/docker/ca.pem");
                    $compute->sshUploadFile("config/ca.crt", "/usr/local/share/ca-certificates/ca.{$this->tls['common-name']}.crt");
                    $compute->sshRun("update-ca-certificates");
                }
            }
        }
        CloudDoctor::Monolog()->addNotice("        │");
    }

    public function restartDocker()
    {
        if ($this->getCompute()) {
            foreach ($this->getCompute() as $compute) {
                if (stripos($compute->sshRun('ls -l /lib/systemd/system/docker.service'), "No such file or directory") === false) {
                    // systemd present :(
                    $compute->sshRun("sed -i 's/ExecStart=.*/ExecStart\=\/usr\/bin\/dockerd/' /lib/systemd/system/docker.service");
                    $compute->sshRun("systemctl daemon-reload");
                    $compute->sshRun("systemctl restart docker.service");
                } else {
                    // systemd not present :)
                    $compute->sshRun("/etc/init.d/docker restart");
                }
            }
        }
    }

    /**
     * @return array
     */
    public function isTls(): bool
    {
        return !empty($this->tls);
    }

    /**
     * @return bool
     */
    public function isExperimental(): bool
    {
        return $this->experimental;
    }

    /**
     * @param bool $experimental
     * @return ComputeGroup
     */
    public function setExperimental(bool $experimental): ComputeGroup
    {
        $this->experimental = $experimental;
        return $this;
    }

    /**
     * @return array
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * @param array $labels
     * @return ComputeGroup
     */
    public function setLabels(array $labels): ComputeGroup
    {
        $this->labels = $labels;
        return $this;
    }

    /**
     * Decide if scaling is required. Scale up would be represented by a positive int, Scale down would be represented by a negative int
     * @return int
     */
    public function isScalingRequired() : int
    {
        return $this->getScale() - $this->countComputes();
    }

    /**
     * Return the number of running, active instances on the upstream provider.
     * @return int
     */
    public function countComputes() : int
    {
        CloudDoctor::Monolog()->warning("Cannot count computes using " . __CLASS__ . ".");
        return $this->getScale();
    }

    /**
     * @return int
     */
    public function getScale(): int
    {
        return $this->scale;
    }

    /**
     * @param int $scale
     * @return ComputeGroup
     */
    public function setScale(int $scale): ComputeGroup
    {
        $this->scale = $scale;
        return $this;
    }

    public function scaleUp()
    {
        $this->getCloudDoctor()->deploy_ComputeGroup($this);
    }

    public function scaleDown()
    {
        CloudDoctor::Monolog()->warn("cannot scale down using " . __CLASS__ . ".");
    }

    /**
     * @return CloudDoctor
     */
    public function getCloudDoctor(): CloudDoctor
    {
        return $this->cloudDoctor;
    }

    /**
     * @param CloudDoctor $cloudDoctor
     * @return ComputeGroup
     */
    public function setCloudDoctor(CloudDoctor $cloudDoctor): ComputeGroup
    {
        $this->cloudDoctor = $cloudDoctor;
        return $this;
    }
    
    public function updateMetaData() : void
    {
        if(is_array($this->getCompute())) {
            foreach ($this->getCompute() as $compute) {
                $compute->updateMetaData();
            }
        }
    }
}
