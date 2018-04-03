<?php
/**
 * @author Evgeny Sisoev
 *
 * Интерфейсы для проверки файлов разными спрособами
 */
namespace PhpCsBitBucket\Checker;

use Monolog\Logger;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\DummyFile;
use PHP_CodeSniffer\Files\FileList;
use PHP_CodeSniffer\Ruleset;
use PhpCsBitBucket\CheckerResult\CheckerResultItem;
use PhpCsBitBucket\CheckerResult\CheckerResultItemInterface;

class PhpCs implements CheckerInterface
{
    /**
     * @var array
     */
    private $config;

     /**
     * @var Config
     */
    private $phpcsConfig;

     /**
     * @var Ruleset
     */
    private $phpcsRuleset;

    /**
     * @var Logger
     */
    private $log;

    /**
     * @param Logger   $log
     * @param array $config - ['encoding' => '....', 'standard' => '...']
     */
    public function __construct(Logger $log, array $config)
    {
        $this->log = $log;
        $this->config = $config;

        require_once('vendor/squizlabs/php_codesniffer/autoload.php');

        $this->phpcsConfig = $this->createPhpCsConfig($config);

        $this->phpcsRuleset = new Ruleset($this->phpcsConfig);

        $this->log->debug("PhpCs config", $config);
    }

    /**
     * @param array $config
     *
     * @return Config
     */
    protected function createPhpCsConfig(array $config)
    {
        $phpcsConfig = new Config(['--encoding=' . $config['encoding']]);
        $phpcsConfig->interactive = false;
        $phpcsConfig->cache = false;
        $phpcsConfig->standards = [$config['standard']];
        $phpcsConfig::setConfigData('installed_paths', $config['installed_paths'], true);

        return $phpcsConfig;
    }

    /**
     * @param string $filename
     * @param string $extension
     *
     * @return bool
     */
    public function shouldIgnoreFile($filename, $extension)
    {
        if (!preg_match($this->config['fileMaskRegex'], $filename)) {
            return true;
        }

        $fileList = new FileList($this->phpcsConfig, $this->phpcsRuleset);
        $fileList->addFile($filename);

        if ($fileList->valid() === false) {
            return true;
        }

        return false;
    }

    /**
     * @param string $filename
     * @param string $extension
     * @param string $fileContent
     *
     * @return CheckerResultItemInterface[]
     */
    public function processFile($filename, $extension, $fileContent)
    {
        $this->phpcsConfig->stdinPath = $filename;
        $file = new DummyFile($fileContent, $this->phpcsRuleset, $this->phpcsConfig);
        $file->process();

        $errors = $file->getErrors();

        $result = [];

        foreach ($errors as $line => $list) {
            foreach ($list as $column => $messages) {
                foreach ($messages as $message) {
                    $result[] = new CheckerResultItem($line, $message['message']);
                }
            }
        }

        return $result;
    }
}
