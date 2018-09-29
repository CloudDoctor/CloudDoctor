<?php

namespace CloudDoctor;

use CloudDoctor\Common\Compute;
use CloudDoctor\Common\ComputeGroup;
use CloudDoctor\Common\DnsEnforcer;
use CloudDoctor\Common\Request;
use CloudDoctor\Common\Swarmifier;
use CloudDoctor\Exceptions\CloudDefinitionException;
use CloudDoctor\Linode\DNSController;
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

    static public function getRequester($name): ?Request
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

    public function assertFromFile(string $fileName, string $overrideFileName = null, string $automaticControlOverrideFile = null)
    {
        if (!file_exists($fileName)) {
            throw new CloudDefinitionException("Cannot find definition file \"{$fileName}\"!");
        }
        $cloudDefinition = \Symfony\Component\Yaml\Yaml::parseFile($fileName);

        if ($overrideFileName && file_exists($overrideFileName)) {
            $cloudOverrideDefinition = \Symfony\Component\Yaml\Yaml::parseFile($overrideFileName);
            $cloudDefinition = $this->arrayOverwrite($cloudDefinition, $cloudOverrideDefinition);
        }

        if ($automaticControlOverrideFile && file_exists($automaticControlOverrideFile)) {
            $automaticControlOverrideDefinition = \Symfony\Component\Yaml\Yaml::parseFile($automaticControlOverrideFile);
            $cloudDefinition = $this->arrayOverwrite($cloudDefinition, $automaticControlOverrideDefinition);
        }

        $this->assert($cloudDefinition);
    }

    private function arrayOverwrite(array & $array1, array & $array2)
    {
        $merged = $array1;

        foreach ($array2 as $key => & $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayOverwrite($merged[$key], $value);
            } else if (is_numeric($key)) {
                if (!in_array($value, $merged))
                    $merged[] = $value;
            } else
                $merged[$key] = $value;
        }

        return $merged;
    }

    public function assert(array $cloudDefinition) : CloudDoctor
    {

        if (!isset($cloudDefinition['authorized-keys']) && getenv('HOME') && file_exists(getenv('HOME') . "/.ssh/id_rsa.pub")) {
            self::Monolog()->warning("No .authorized-keys element in config, assuming ~/.ssh/id_rsa.pub");
            $cloudDefinition['authorized-keys'][] = trim(file_get_contents(getenv('HOME') . "/.ssh/id_rsa.pub"));
            self::$privateKeys[] = trim(file_get_contents(getenv('HOME') . "/.ssh/id_rsa"));
        }
        if(isset($cloudDefinition['logging'])) {
            $this->setupMonolog($cloudDefinition['logging']);
        }else{
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
        if(isset($loggingConfig['sentry']) && isset($loggingConfig['sentry']['api-key'])) {
            $client = new \Raven_Client($loggingConfig['sentry']['api-key']);
            $handler = new \Monolog\Handler\RavenHandler(
                $client,
                isset($loggingConfig['sentry']['level']) ? $loggingConfig['sentry']['level'] : Logger::EMERGENCY
            );
            $handler->setFormatter(new \Monolog\Formatter\LineFormatter("%message% %context% %extra%\n"));
            self::$monolog->pushHandler($handler);
        }

        if(isset($loggingConfig['rollbar']) && isset($loggingConfig['rollbar']['api-key'])) {
            $config = array(
                'access_token' => $loggingConfig['rollbar']['api-key'],
                'environment' => isset($loggingConfig['rollbar']['environment']) ? $loggingConfig['rollbar']['environment'] : 'local',
                isset($loggingConfig['rollbar']['level']) ? $loggingConfig['rollbar']['level'] : Logger::EMERGENCY,
            );
            Rollbar::init($config);
            self::$monolog->pushHandler(new PsrHandler(Rollbar::logger()));
        }

        if(isset($loggingConfig['telegram']) && isset($loggingConfig['telegram']['api-key']) && isset($loggingConfig['telegram']['chat-id'])){
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
            $computeGroup = ComputeGroup::Factory($groupName, $config);
            $providers = array_keys($config['provider']);
            for ($i = 1; $i <= $config['scale']; $i++) {
                $providerName = $providers[rand(0, count($providers) - 1)];
                $provider = $config['provider'][$providerName];
                $providerNameUC = ucfirst($providerName);

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

    public function deploy()
    {

        self::Monolog()->addDebug("DEPLOY──┐");
        foreach (self::$computeGroups as $computeGroup) {
            $computeGroup->deploy();
            $computeGroup->waitForRunning();
            $computeGroup->setHostNames();
            $computeGroup->runScript('install');
        }

        $roleGroups = [];
        CloudDoctor::Monolog()->addDebug("        ├┬ Dockerisation:");
        foreach (self::$computeGroups as $computeGroup) {
            self::Monolog()->addDebug("        │├┬ {$computeGroup->getGroupName()} has role {$computeGroup->getRole()}...");
            if ($computeGroup->getCompute()) {
                foreach ($computeGroup->getCompute() as $compute) {
                    $roleGroups[$computeGroup->getRole()][] = $compute;
                }
            }
            if ($computeGroup->isTls()) {
                if (!$this->certificatesValid()) {
                    $computeGroup->generateTls();
                    CloudDoctor::Monolog()->addDebug("        ││└ Certificates generated!");
                }else{
                    CloudDoctor::Monolog()->addDebug("        ││└ Certificates valid!");
                }
            }
            $computeGroup->applyDockerEngineConfig();
        }

        $swarmifier = new Swarmifier(
            isset($roleGroups['manager']) ? $roleGroups['manager'] : null,
            isset($roleGroups['worker']) ? $roleGroups['worker'] : null
        );

        $swarmifier->swarmify();

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

    private function certificatesValid()
    {
        $okay = true;
        foreach (['ca.pem', 'server-cert.pem', 'server-key.pem', 'client-cert.pem', 'client-key.pem'] as $cert) {
            if (!(file_exists($cert) && filesize($cert) > 0)) {
                $okay = false;
            }
        }
        return $okay;
    }

    /**
     * @return Logger
     */
    static public function Monolog(): Logger
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