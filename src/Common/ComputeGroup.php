<?php

namespace CloudDoctor\Common;

use CloudDoctor\CloudDoctor;
use CloudDoctor\Exceptions\CloudDoctorException;
use CloudDoctor\Exceptions\CloudScriptExecutionException;
use CloudDoctor\Interfaces\ComputeInterface;

class ComputeGroup extends Entity
{
    const ALWAYS_REDEPLOY = false;

    /** @var string */
    protected $role = 'worker';
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
    /** @var Compute[] */
    private $compute;

    public function __construct($groupName = null, $config = null)
    {
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
        }
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

    public static function Factory($groupName = null, $config = null): ComputeGroup
    {
        return new ComputeGroup($groupName, $config);
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

    /**
     * @param Compute $compute
     * @return ComputeGroup
     */
    public function addCompute(Compute $compute)
    {
        $this->compute[] = $compute;
        return $this;
    }

    public function deploy()
    {
        CloudDoctor::Monolog()->addDebug("        ├┬ Compute Group '{$this->getGroupName()}':");
        if ($this->getCompute()) {
            foreach ($this->getCompute() as $i => $compute) {
                /** @var $compute ComputeInterface */
                CloudDoctor::Monolog()->addDebug("        │├┬ {$compute->getName()} ( {$compute->getHostName()} ):");
                if (!$compute->exists()) {
                    $compute->deploy();
                } else {
                    CloudDoctor::Monolog()->addDebug("        ││├ {$compute->getName()} already exists...");
                    if ($compute->isTransitioning()) {
                        CloudDoctor::Monolog()->addDebug("        ││└ {$compute->getName()} is changing state!...");
                    }
                    if ($compute->isRunning()) {
                        if (!$compute->sshOkay() || self::ALWAYS_REDEPLOY) {
                            CloudDoctor::Monolog()->addDebug("        ││├ {$compute->getName()} cannot be ssh'd into!...");
                            $compute->destroy();
                            $compute->deploy();
                        } else {
                            CloudDoctor::Monolog()->addDebug("        ││└ Already Running!");
                            if ($i != count($this->getCompute()) - 1) {
                                CloudDoctor::Monolog()->addDebug("        ││");
                            }else{
                                CloudDoctor::Monolog()->addDebug("        │");
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
        CloudDoctor::Monolog()->addDebug("        │├┬ Waiting for Compute Group '{$this->getGroupName()}' to be running...");
        while (!$this->isRunning()) {
            sleep(1);
        }
        CloudDoctor::Monolog()->addDebug("        ││└ Done!");
        CloudDoctor::Monolog()->addDebug("        ││");
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
            CloudDoctor::Monolog()->addDebug("        ├┬ Running Scripts: {$name}");

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
                                CloudDoctor::Monolog()->addDebug("        │├┬ {$compute->getName()} Running '{$script['command']}'");
                                $lines = explode("\n", $response);
                                foreach ($lines as $i => $line) {
                                    if($i == count($lines) - 1) {
                                        CloudDoctor::Monolog()->addDebug("        ││└─ {$line}");
                                    } else {
                                        CloudDoctor::Monolog()->addDebug("        ││├─ {$line}");
                                    }
                                }
                            }else{
                                CloudDoctor::Monolog()->addDebug("        │├─ {$compute->getName()} Running '{$script['command']}'");
                            }
                        } else {
                            CloudDoctor::Monolog()->addDebug("        │├─ {$compute->getName()} Skipping '{$script['command']}'");
                        }
                        CloudDoctor::Monolog()->addDebug("        ││");
                    }
                }
            }
            CloudDoctor::Monolog()->addDebug("        │");
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

    public function generateTls()
    {
        $caPassword = CloudDoctor::generatePassword();
        $ips = "IP:" . ltrim(implode(",IP:", $this->getPublicIps()), ",");
        $computes = $this->getCompute();
        $masterCompute = $computes[array_rand($computes, 1)];

        $this->generateTlsCleanup($masterCompute);

        // Making a CA...
        CloudDoctor::Monolog()->addDebug("        ││├┬ Generating CA certificate");
        $masterCompute->sshRun("openssl genrsa -aes256 -passout pass:{$caPassword} -out ca-key.pem 4096");
        $masterCompute->sshRun("openssl req -new -x509 -passin pass:{$caPassword} -days 365 -key ca-key.pem -sha256 -out ca.pem -subj \"{$this->tls['subject']}\"");
        $masterCompute->sshRun("chmod -v 0444 ca.pem");
        $masterCompute->sshDownloadFile("ca.pem", "config/ca.pem");
        CloudDoctor::Monolog()->addDebug("        │││└ Downloaded ca.pem");

        // Making a server cert
        CloudDoctor::Monolog()->addDebug("        ││├┬ Generating server certificate");
        $masterCompute->sshRun("openssl genrsa -out server-key.pem 4096");
        $masterCompute->sshRun("openssl req -subj \"/CN={$this->tls['common-name']}\" -sha256 -new -key server-key.pem -out server.csr");
        $masterCompute->sshRun("echo subjectAltName = DNS:{$this->tls['common-name']},{$ips} >> extfile.server.cnf");
        $masterCompute->sshRun("echo extendedKeyUsage = serverAuth >> extfile.server.cnf");
        $masterCompute->sshRun("cat extfile.server.cnf");
        $masterCompute->sshRun("openssl x509 -req -days 365 -sha256 -in server.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -out server-cert.pem -extfile extfile.server.cnf -passin pass:{$caPassword}");
        $masterCompute->sshRun("chmod -v 0444 server-cert.pem server-key.pem");
        $masterCompute->sshDownloadFile("server-cert.pem", "config/server-cert.pem");
        $masterCompute->sshDownloadFile("server-key.pem", "config/server-key.pem");
        CloudDoctor::Monolog()->addDebug("        │││└ Downloaded server-cert.pem server-key.pem");

        // Now making a client cert
        CloudDoctor::Monolog()->addDebug("        ││├┬ Generating client certificate");
        $masterCompute->sshRun("openssl genrsa -out key.pem 4096");
        $masterCompute->sshRun("openssl req -subj '/CN=client' -new -key client-key.pem -out client.csr");
        $masterCompute->sshRun("echo extendedKeyUsage = clientAuth >> extfile.client.cnf");
        $masterCompute->sshRun("openssl x509 -req -days 365 -sha256 -in client.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -out client-cert.pem -extfile extfile.client.cnf -passin pass:{$caPassword}");
        $masterCompute->sshRun("chmod -v 0444 cert.pem");
        $masterCompute->sshDownloadFile("client-cert.pem", "config/client-cert.pem");
        $masterCompute->sshDownloadFile("client-key.pem", "config/client-key.pem");
        CloudDoctor::Monolog()->addDebug("        │││└ Downloaded client-cert.pem & client-key.pem");

        // Post generation cleanup
        $this->generateTlsCleanup($masterCompute);
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
        $masterCompute->sshRun("rm -f ca-key.pem ca.pem ca.srl server-key.pem server.csr client.csr extfile.server.cnf extfile.client.cnf server-cert.pem cert.pem key.pem");
    }

    public function setHostNames()
    {
        CloudDoctor::Monolog()->addDebug("        │");
        if ($this->getCompute()) {
            foreach ($this->getCompute() as $compute) {
                CloudDoctor::Monolog()->addDebug("        ├┬ Hostname check: {$compute->getName()}");
                $currentHostname = $compute->sshRun("cat /etc/hostname");
                $newHostname = $compute->getHostName();
                CloudDoctor::Monolog()->addDebug("        │├ Should be '{$newHostname}'.");
                if ($currentHostname != $newHostname) {
                    // Set hostname
                    $compute->sshRun("echo \"{$newHostname}\" > /etc/hostname");
                    $compute->sshRun("echo -e \"127.0.0.1\t{$newHostname}\n$(cat /etc/hosts)\" > /etc/hosts");
                    $compute->sshUploadFile("config/hostname.sh", "hostname.sh");
                    $compute->sshRun("/bin/bash hostname.sh; rm hostname.sh");
                    CloudDoctor::Monolog()->addDebug("        │└ Renamed '{$currentHostname}' to '{$newHostname}'...");
                }else{
                    CloudDoctor::Monolog()->addDebug("        │└ Hostname already correct.");
                }
            }
        }
        CloudDoctor::Monolog()->addDebug("        │");
    }

    public function applyDockerEngineConfig()
    {
        if ($this->getCompute()) {
            foreach ($this->getCompute() as $compute) {
                $hosts = [];
                $hosts[] = 'unix:///var/run/docker.sock';
                if ($compute->getComputeGroup()->isTls()) {
                    $hosts[] = 'tcp://' . $compute->getPublicIp() . ":2376";
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

                $comparisonFile = tempnam("/tmp", "comparison-");
                $compute->sshDownloadFile("/etc/docker/daemon.json", $comparisonFile);
                if(file_get_contents($daemonFilePath) != file_get_contents($comparisonFile)){
                    $compute->sshUploadFile($daemonFilePath, "/etc/docker/daemon.json");
                    if ($compute->getComputeGroup()->isTls()) {
                        $compute->sshUploadFile("config/server-cert.pem", "/etc/docker/server-cert.pem");
                        $compute->sshUploadFile("config/server-key.pem", "/etc/docker/server-key.pem");
                        $compute->sshUploadFile("config/ca.pem", "/etc/docker/ca.pem");
                    }

                    if (stripos($compute->sshRun('ls -l /lib/systemd/system/docker.service'), "No such file or directory") === false) {
                        // systemd present :(
                        $compute->sshRunDebug("sed -i 's/ExecStart=.*/ExecStart\=\/usr\/bin\/dockerd/' /lib/systemd/system/docker.service");
                        $compute->sshRunDebug("systemctl daemon-reload");
                        $compute->sshRunDebug("systemctl restart docker.service");
                    } else {
                        // systemd not present :)
                        $compute->sshRunDebug("/etc/init.d/docker restart");
                    }
                }
                unlink($comparisonFile);
            }
        }
        CloudDoctor::Monolog()->addDebug("        │");
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

}