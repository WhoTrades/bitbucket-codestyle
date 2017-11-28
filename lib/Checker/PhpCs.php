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
     * @param array $phpcs - ['encoding' => '....', 'standard' => '...']
     */
    public function __construct(Logger $log, array $config)
    {
        $this->log = $log;

        require_once(__DIR__ . '/../../vendor/squizlabs/php_codesniffer/autoload.php');
        $this->phpcsConfig = new Config(['--encoding=' . $config['encoding']]);
        $this->phpcsConfig->interactive = false;
        $this->phpcsConfig->cache = false;
        $this->phpcsConfig->standards = [$config['standard']];

        $this->phpcsRuleset = new Ruleset($this->phpcsConfig);

        $this->log->debug("PhpCs config", $config);


    }

    /**
     * @param string $filename
     * @param string $extension
     * @return bool
     */
    public function shouldIgnoreFile($filename, $extension)
    {
        $fileList = new FileList($this->phpcsConfig, $this->phpcsRuleset);

        $dir = preg_replace('~[^/]*$~', '', $filename) ?: "./";
        $file = preg_replace('~^.*/~', '', $filename);

        $fileList->addFile($dir, $file);

        return $fileList->valid() === false;
    }

    /**
     * @param string $filename
     * @param string $extension
     * @param string $fileContent
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
