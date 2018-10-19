<?php

namespace CloudDoctor;

use CloudDoctor\Common\Compute;
use CloudDoctor\Common\ComputeGroup;
use CloudDoctor\Common\DnsEnforcer;
use CloudDoctor\Common\Request;
use CloudDoctor\Common\Swarmifier;
use CloudDoctor\Exceptions\CloudDefinitionException;
use CloudDoctor\Interfaces\ComputeGroupInterface;
use CloudDoctor\Linode\DNSController;
use GuzzleHttp\Exception\ClientException;
use Hackzilla\PasswordGenerator\Generator\ComputerPasswordGenerator;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\PsrHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use rahimi\TelegramHandler\TelegramHandler;
use Rollbar\Rollbar;

class CloudDoctor
{
    /** @var string[] */
    static public $privateKeys = [];
    /** @var ComputeGroup[] */
    static private $computeGroups;
    /** @var Request[] */
    static private $requesters;
    /** @var DNSController */
    static private $dnsControllers;
    /** @var Logger */
    static private $monolog;

    /** @var int[] */
    private $fileMD5s;

    /** @var string */
    private $name;

    public function __construct()
    {
        self::$monolog = new Logger('CloudDoctor');

        $formatter = new LineFormatter("%level_name%: %message%\n", null, false, true);

        $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $streamHandler->setFormatter($formatter);
        self::$monolog->pushHandler($streamHandler); // <<< uses a stream
    }

    /**
     * @return int[]
     */
    public function getFileMD5s(): array
    {
        return $this->fileMTimes;
    }

    /**
     * @param int[] $fileMTimes
     * @return CloudDoctor
     */
    public function setFileMD5s(array $fileMD5s): CloudDoctor
    {
        $this->fileMTimes = $fileMTimes;
        return $this;
    }

    public static function getRequester($name): ?Request
    {
        return self::$requesters[$name];
    }

    public static function generatePassword(): string
    {
        $passwordGenerator = new ComputerPasswordGenerator();
        $passwordGenerator
            ->setOptionValue(ComputerPasswordGenerator::OPTION_UPPER_CASE, true)
            ->setOptionValue(ComputerPasswordGenerator::OPTION_LOWER_CASE, true)
            ->setOptionValue(ComputerPasswordGenerator::OPTION_NUMBERS, true)
            ->setOptionValue(ComputerPasswordGenerator::OPTION_SYMBOLS, false)
            ->setLength(64);
        return $passwordGenerator->generatePassword();
    }

    public function assertFromFile(
        string $fileName,
        string $overrideFileName = null,
        string $automaticControlOverrideFile = null
    ) {
        $this->fileMD5s = [];

        if (!file_exists($fileName)) {
            throw new CloudDefinitionException("Cannot find definition file \"{$fileName}\"!");
        }
        $cloudDefinition = \Symfony\Component\Yaml\Yaml::parseFile($fileName);
        $this->fileMD5s[$fileName] = md5_file($fileName);

        if ($overrideFileName && file_exists($overrideFileName)) {
            $cloudOverrideDefinition = \Symfony\Component\Yaml\Yaml::parseFile($overrideFileName);
            $cloudDefinition = $this->arrayOverwrite($cloudDefinition, $cloudOverrideDefinition ?? []);
            $this->fileMD5s[$overrideFileName] = md5_file($overrideFileName);
        }

        if ($automaticControlOverrideFile && file_exists($automaticControlOverrideFile)) {
            $automaticControlOverrideDefinition = \Symfony\Component\Yaml\Yaml::parseFile($automaticControlOverrideFile);
            $cloudDefinition = $this->arrayOverwrite($cloudDefinition, $automaticControlOverrideDefinition ?? []);
            $this->fileMD5s[$automaticControlOverrideFile] = md5_file($automaticControlOverrideFile);
        }

        $this->assert($cloudDefinition);
    }

    private function arrayOverwrite(array $array1, array $array2 = null)
    {
        if ($array2 === null || !is_array($array2) || count($array2) == 0) {
            return $array1;
        }
        $merged = $array1;

        foreach ($array2 as $key => & $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayOverwrite($merged[$key], $value);
            } elseif (is_numeric($key)) {
                if (!in_array($value, $merged)) {
                    $merged[] = $value;
                }
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    public function assert(array $cloudDefinition) : CloudDoctor
    {
        if (!isset($cloudDefinition['authorized-keys']) && getenv('HOME') && file_exists(getenv('HOME') . "/.ssh/id_rsa.pub")) {
            self::Monolog()->warning("No .authorized-keys element in config, assuming ~/.ssh/id_rsa.pub");
            $cloudDefinition['authorized-keys'][] = trim(file_get_contents(getenv('HOME') . "/.ssh/id_rsa.pub"));
            #self::$publicKeys[] = trim(file_get_contents(getenv('HOME') . "/.ssh/id_rsa.pub"))
            self::$privateKeys[] = trim(file_get_contents(getenv('HOME') . "/.ssh/id_rsa"));
            //@todo handle privatekeys coming from the config rather than files.
        }
        if (isset($cloudDefinition['logging'])) {
            $this->setupMonolog($cloudDefinition['logging']);
        } else {
            self::Monolog()->warning("No logging services set up!");
        }
        $this->validateDefinition($cloudDefinition);
        $this->setName($cloudDefinition['name']);
        self::$monolog = self::$monolog->withName("Cloud Doctor: {$this->getName()}");
        self::Monolog()->debug("Cloud Doctor: {$this->getName()}");
        $this->createRequesters($cloudDefinition['credentials']);
        $this->createDnsControllers($cloudDefinition['credentials']);
        $this->createInstances($cloudDefinition['instances'], $cloudDefinition['authorized-keys']);
        return $this;
    }

    private function setupMonolog(array $loggingConfig)
    {
        if (isset($loggingConfig['sentry']) && isset($loggingConfig['sentry']['api-key'])) {
            $client = new \Raven_Client($loggingConfig['sentry']['api-key']);
            $handler = new \Monolog\Handler\RavenHandler(
                $client,
                isset($loggingConfig['sentry']['level']) ? $loggingConfig['sentry']['level'] : Logger::EMERGENCY
            );
            $handler->setFormatter(new \Monolog\Formatter\LineFormatter("%message% %context% %extra%\n"));
            self::$monolog->pushHandler($handler);
        }

        if (isset($loggingConfig['rollbar']) && isset($loggingConfig['rollbar']['api-key'])) {
            $config = array(
                'access_token' => $loggingConfig['rollbar']['api-key'],
                'environment' => isset($loggingConfig['rollbar']['environment']) ? $loggingConfig['rollbar']['environment'] : 'local',
                isset($loggingConfig['rollbar']['level']) ? $loggingConfig['rollbar']['level'] : Logger::EMERGENCY,
            );
            Rollbar::init($config);
            self::$monolog->pushHandler(new PsrHandler(Rollbar::logger()));
        }

        if (isset($loggingConfig['telegram']) && isset($loggingConfig['telegram']['api-key']) && isset($loggingConfig['telegram']['chat-id'])) {
            self::$monolog->pushHandler(
                new TelegramHandler(
                    $loggingConfig['telegram']['api-key'],
                    $loggingConfig['telegram']['chat-id'],
                    'UTC',
                    'F j, Y, g:i a',
                    isset($loggingConfig['telegram']['level']) ? $loggingConfig['telegram']['level'] : Logger::EMERGENCY
                )
            );
        }
    }

    private function validateDefinition(array $cloudDefinition)
    {
        foreach (['name', 'credentials', 'authorized-keys', 'instances'] as $rootElement) {
            if (!isset($cloudDefinition[$rootElement])) {
                throw new CloudDefinitionException("Configuration requires .{$rootElement} field!");
            }
        }
    }

    private function createRequesters($credentials)
    {
        foreach ($credentials as $providerName => $config) {
            $providerNameUC = ucfirst($providerName);
            $requesterClass = class_exists("\\CloudDoctor\\{$providerNameUC}\\Request") ? "\\CloudDoctor\\{$providerNameUC}\\Request" : Request::class;
            /** @var Request $requester */
            $requester = new $requesterClass($config);
            self::$requesters[$providerName] = $requester;
        }
    }

    private function createDnsControllers($credentials)
    {
        foreach ($credentials as $providerName => $config) {
            $providerNameUC = ucfirst($providerName);
            $dnsController = class_exists("\\CloudDoctor\\{$providerNameUC}\\DNSController") ? "\\CloudDoctor\\{$providerNameUC}\\DNSController" : DNSController::class;
            /** @var DNSController $requester */
            $dnsController = new $dnsController($config);
            self::$dnsControllers[$providerName] = $dnsController;
        }
    }

    private function createInstances($instances, $authorizedKeys)
    {
        foreach ($instances as $groupName => $config) {
            // Calculate provider name into a class-suitablke name
            $providers = array_keys($config['provider']);
            $providerName = $providers[rand(0, count($providers) - 1)];
            $provider = $config['provider'][$providerName];
            $providerNameUC = ucfirst($providerName);

            // instantiate a Compute Group.
            $computeGroupClass = class_exists("\\CloudDoctor\\{$providerNameUC}\\ComputeGroup") ? "\\CloudDoctor\\{$providerNameUC}\\ComputeGroup" : ComputeGroup::class;
            /** @var ComputeGroup $computeGroup */
            $computeGroup = $computeGroupClass::Factory($this, $groupName, $config, self::$requesters[$providerName]);
            $computeGroup->setScale($config['scale']);
            for ($i = 1; $i <= $config['scale']; $i++) {
                $computeClass = class_exists("\\CloudDoctor\\{$providerNameUC}\\Compute") ? "\\CloudDoctor\\{$providerNameUC}\\Compute" : Compute::class;
                /** @var Compute $compute */
                $compute = new $computeClass($computeGroup, $provider);
                $compute->setGroupIndex($i);
                $compute->setRequester(self::$requesters[$providerName]);
                foreach ($authorizedKeys as $authorizedKey) {
                    $compute->addAuthorizedKey($authorizedKey);
                }
                $computeGroup->addCompute($compute);
            }
            self::$computeGroups[] = $computeGroup;
        }
    }

    public function updateMetaData() :void
    {
        self::Monolog()->addNotice("UPDMETA─┐");
        foreach (self::$computeGroups as $i => $computeGroup) {
            CloudDoctor::Monolog()->addNotice("        ├┬ Compute Group \"{$computeGroup->getGroupName()}\"");
            $computeGroup->updateMetaData();
            CloudDoctor::Monolog()->addNotice("        │└─ Done!");
        }
    }

    public function updateStacks() : void
    {
        self::Monolog()->addNotice("STACKS──┐");
        $roleGroups = [];

        foreach (self::$computeGroups as $computeGroup) {
            if ($computeGroup->getCompute()) {
                foreach ($computeGroup->getCompute() as $compute) {
                    $roleGroups[$computeGroup->getRole()][] = $compute;
                }
            }
        }

        $swarmifier = new Swarmifier(
            isset($roleGroups['manager']) ? $roleGroups['manager'] : null,
            isset($roleGroups['worker']) ? $roleGroups['worker'] : null
        );

        $swarmifier->assertDefaultNetworks();

        $directory = new \DirectoryIterator("stacks/");
        foreach ($directory as $file) {
            if ($file->isFile() && !$file->isDot() && $file->getExtension() == 'yml') {
                $name = str_replace(".yml", "", $file->getFilename());
                $swarmifier->assertStack($name, $file);
            }
        }
    }

    public function deploy() :void
    {
        self::Monolog()->addNotice("DEPLOY──┐");
        foreach (self::$computeGroups as $computeGroup) {
            $computeGroup->deploy();
            $computeGroup->updateMetaData();
            $computeGroup->waitForRunning();
            $computeGroup->setHostNames();
            $computeGroup->runScript('install');
        }

        $roleGroups = [];
        CloudDoctor::Monolog()->addNotice("        ├┬ Dockerisation:");
        foreach (self::$computeGroups as $computeGroup) {
            self::Monolog()->addNotice("        │├┬ {$computeGroup->getGroupName()} has role {$computeGroup->getRole()}...");
            if ($computeGroup->getCompute()) {
                foreach ($computeGroup->getCompute() as $compute) {
                    $roleGroups[$computeGroup->getRole()][] = $compute;
                }
            }
            if ($computeGroup->isTls()) {
                if (!$this->certificatesValid()) {
                    $computeGroup->generateTls();
                    CloudDoctor::Monolog()->addNotice("        ││└ Certificates generated!");
                } else {
                    CloudDoctor::Monolog()->addNotice("        ││└ Certificates valid!");
                }
            }
            $computeGroup->applyDockerEngineConfig();
            $computeGroup->restartDocker();
        }
        $this->deploy_swarmify();
        $this->deploy_dnsEnforce();
        $this->updateStacks();
    }

    public function deploy_swarmify() : void
    {
        $roleGroups = [];

        foreach (self::$computeGroups as $computeGroup) {
            if ($computeGroup->getCompute()) {
                foreach ($computeGroup->getCompute() as $compute) {
                    $roleGroups[$computeGroup->getRole()][] = $compute;
                }
            }
        }

        $swarmifier = new Swarmifier(
            isset($roleGroups['manager']) ? $roleGroups['manager'] : null,
            isset($roleGroups['worker']) ? $roleGroups['worker'] : null
        );

        $swarmifier->swarmify();

        $this->downloadCerts();
    }

    public function deploy_dnsEnforce() : void
    {
        $dns = new DnsEnforcer(self::$dnsControllers);
        foreach (self::$computeGroups as $computeGroup) {
            if ($computeGroup->getCompute()) {
                foreach ($computeGroup->getCompute() as $compute) {
                    $dns->addCompute($compute);
                }
            }
        }
        $dns->enforce();
    }

    public function deploy_ComputeGroup(ComputeGroup $computeGroup) : CloudDoctor
    {
        $computeGroup->deploy();
        $computeGroup->waitForRunning();
        $computeGroup->setHostNames();
        $computeGroup->runScript('install');
        $roleGroups = [];
        CloudDoctor::Monolog()->addNotice("        ├┬ Dockerisation:");
        self::Monolog()->addNotice("        │├┬ {$computeGroup->getGroupName()} has role {$computeGroup->getRole()}...");
        if ($computeGroup->getCompute()) {
            foreach ($computeGroup->getCompute() as $compute) {
                $roleGroups[$computeGroup->getRole()][] = $compute;
            }
        }
        if ($computeGroup->isTls()) {
            if (!$this->certificatesValid()) {
                $computeGroup->generateTls();
                CloudDoctor::Monolog()->addNotice("        ││└ Certificates generated!");
            } else {
                CloudDoctor::Monolog()->addNotice("        ││└ Certificates valid!");
            }
        }
        $computeGroup->applyDockerEngineConfig();
        $this->deploy_swarmify();
        $this->deploy_dnsEnforce();
        return $this;
    }

    public function show() : void
    {
        self::Monolog()->addNotice("SCHEMA──┐");
        foreach (self::$computeGroups as $computeGroup) {
            CloudDoctor::Monolog()->addNotice("        ├┬ Compute Group: {$computeGroup->getGroupName()}");
            foreach ($computeGroup->getCompute() as $compute) {
                CloudDoctor::Monolog()->addNotice("        │├┬ Compute: {$compute->getName()}");
                CloudDoctor::Monolog()->addNotice("        ││├ Hostname: {$compute->getHostName()}");
                CloudDoctor::Monolog()->addNotice("        ││├┬ DNS Entries:");
                foreach ($compute->getHostNames() as $hostname) {
                    CloudDoctor::Monolog()->addNotice("        │││├ {$hostname}");
                }
            }
            CloudDoctor::Monolog()->addNotice("        │");
        }
    }

    public function purge() : void
    {
        self::Monolog()->addNotice("PURGE───┐");
        foreach (self::$computeGroups as $computeGroup) {
            CloudDoctor::Monolog()->addNotice("        ├┬ Deleting Compute Group: {$computeGroup->getGroupName()}");
            foreach ($computeGroup->getCompute() as $compute) {
                CloudDoctor::Monolog()->addNotice("        │├┬ Deleting Compute: {$compute->getName()}");
                if ($compute->destroy()) {
                    CloudDoctor::Monolog()->addNotice("        ││└─ Deleted!");
                } else {
                    CloudDoctor::Monolog()->addNotice("        ││└─ Could not be deleted, does it exist?");
                }
            }
            CloudDoctor::Monolog()->addNotice("        │");
        }
    }

    public function scale() : void
    {
        self::Monolog()->addNotice("SCALE───┐");
        foreach (self::$computeGroups as $computeGroup) {
            CloudDoctor::Monolog()->addNotice("        ├┬ Checking Compute Group: {$computeGroup->getGroupName()}");
            $computeGroup->updateMetaData();
            CloudDoctor::Monolog()->addNotice("        │├─ Scale Desired: {$computeGroup->getScale()}");
            CloudDoctor::Monolog()->addNotice("        │├─ Scale Current: {$computeGroup->countComputes()}");
            if ($computeGroup->isScalingRequired() == 0) {
                CloudDoctor::Monolog()->addNotice("        │└─ Nothing to do!");
            } else {
                if ($computeGroup->isScalingRequired() > 0) {
                    CloudDoctor::Monolog()->addNotice("        │└─ Need to scale by {$computeGroup->isScalingRequired()}!");
                    $computeGroup->scaleUp();
                } else {
                    CloudDoctor::Monolog()->addNotice("        │└┬ Need to scale by {$computeGroup->isScalingRequired()}!");
                    $computeGroup->scaleDown();
                    CloudDoctor::Monolog()->addNotice("        │ └─ Deleted!");
                }
            }
            CloudDoctor::Monolog()->addNotice("        │");
        }
    }

    public function watch(Cli $cli) : void
    {
        self::Monolog()->addNotice("Watching for changes...");
        while (true) {
            if ($this->configFilesChanged()) {
                $cli->assertFromFiles();
                $this->scale();
            } else {
                sleep(3);
            }
        }
    }

    public function downloadCerts() : void
    {
        $roleGroups = [];

        foreach (self::$computeGroups as $computeGroup) {
            if ($computeGroup->getCompute()) {
                foreach ($computeGroup->getCompute() as $compute) {
                    $roleGroups[$computeGroup->getRole()][] = $compute;
                }
            }
        }

        $swarmifier = new Swarmifier(
            isset($roleGroups['manager']) ? $roleGroups['manager'] : null,
            isset($roleGroups['worker']) ? $roleGroups['worker'] : null
        );

        $swarmifier->downloadCerts();
        foreach (self::$computeGroups as $computeGroup) {
            if ($computeGroup->getRole() == 'manager') {
                /** @var ComputeGroup */
                $computeGroup->downloadCerts();
            }
        }
    }

    private function configFilesChanged() : bool
    {
        $changed = false;
        foreach ($this->fileMD5s as $filename => $currentMD5) {
            $newMD5 = md5_file($filename);
            if ($currentMD5 != $newMD5) {
                CloudDoctor::Monolog()->debug("File {$filename} has changed!");
                $this->fileMD5s[$filename] = $newMD5;
                $changed = true;
            }
        }
        return $changed;
    }

    private function certificatesValid()
    {
        $okay = true;
        foreach (['ca.pem', 'server-cert.pem', 'server-key.pem', 'client-cert.pem', 'client-key.pem'] as $cert) {
            $file = "config/{$cert}";
            if (!(file_exists($file) && filesize($file) > 0)) {
                echo "{$file} is not OK\n";
                $okay = false;
            }
        }
        return $okay;
    }

    /**
     * @return Logger
     */
    public static function Monolog(): Logger
    {
        return self::$monolog;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return CloudDoctor
     */
    public function setName(string $name): CloudDoctor
    {
        $this->name = $name;
        return $this;
    }
}
