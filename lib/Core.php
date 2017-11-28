<?php
/**
 * @author Artem Naumenko
 *
 * Ядро проекта, подгружает конфигурацию, создает объект логирования
 */
namespace PhpCsBitBucket;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\BrowserConsoleHandler;
use PhpCsBitBucket\Checker\Sequential;

class Core
{
    /** @var BitBucketApi */
    protected $bitBucket;

    /** @var Logger */
    protected $log;

    /** @var array */
    protected $config;

    /**
     * Core constructor.
     * @param string $configFilename путь к ini файлу конфигурации
     */
    public function __construct($configFilename)
    {
        $this->config = parse_ini_file($configFilename, true);

        $this->initLogger();

        $bitBucketConfig = $this->getConfigSection('bitbucket');
        $this->bitBucket = new BitBucketApi(
            $this->getLogger(),
            $bitBucketConfig['url'],
            $bitBucketConfig['username'],
            $bitBucketConfig['password'],
            $bitBucketConfig['timeout']
        );
    }

    protected function initLogger()
    {
        $this->log = new Logger(uniqid());
        $dir = $this->config['logging']['dir']."/";

        $this->log->pushHandler(
            new StreamHandler($dir.date("Y-m-d").".log", $this->config['logging']['verbosityLog'])
        );

        $this->log->pushHandler(
            new StreamHandler($dir.date("Y-m-d").".log", $this->config['logging']['verbosityError'])
        );
		
		if (!empty($this->config['logging']['logToStdOut'])) {
		    $this->log->pushHandler(
			    new StreamHandler("php://stdout", $this->config['logging']['verbosityLog'])
		    );
		}

        $this->log->pushHandler(
            new BrowserConsoleHandler()
        );
    }

    /** @return Logger */
    public function getLogger()
    {
        return $this->log;
    }

    /**
     * @return BitBucketApi
     */
    public function getBitBucket()
    {
        return $this->bitBucket;
    }

    /**
     * @param string $section название секции в ini файле конфигурации
     * @return array
     */
    public function getConfigSection($section)
    {
        return $this->config[$section];
    }

    /**
     * Метод, который запускает работу всего приложения в синхронном режиме
     * @param string $branch
     * @param string $slug
     * @param string $repo
     * @throws \InvalidArgumentException
     * @return array
     */
    public function runSync($branch, $slug, $repo)
    {
        if (empty($branch) || empty($repo) || empty($slug)) {
            $this->getLogger()->warning("Invalid request: empty slug or branch or repo", $_GET);
            throw new \InvalidArgumentException("Invalid request: empty slug or branch or repo");
        }

        $requestProcessor = $this->createRequestProcessor();

        return $requestProcessor->processRequest($slug, $repo, $branch);
    }

    /**
     * @return RequestProcessor
     * @throws Exception\Runtime
     */
    protected function createRequestProcessor()
    {
        $requestProcessor = new RequestProcessor(
            $this->getLogger(),
            $this->getBitBucket(),
            $this->createChecker()
        );

        return $requestProcessor;
    }

    /**
     * @return Checker\CheckerInterface
     * @throws Exception\Runtime
     */
    protected function createChecker()
    {
        $list = explode(",", $this->getConfigSection('checkers')['list']);
        $list = array_map('trim', $list);

        if (count($list) == 1) {
            return $this->createCheckerByName(reset($list));
        } else {
            $checkers = [];
            foreach ($list as $name) {
                $checkers[] = $this->createCheckerByName($name);
            }

            return new Sequential($checkers);
        }
    }

    /**
     * @param string $name
     * @return Checker\CheckerInterface
     */
    private function createCheckerByName(string $name)
    {
        $config = $this->getConfigSection($name);
        $className = $config['className'];
        $checker = new $className($this->log, $config);

        return $checker;
    }
}
