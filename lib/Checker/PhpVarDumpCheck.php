<?php
/**
 *
 * @author     Danil Pyatnitsev <danil@pyatnitsev.ru>
 *
 * @copyright  © 2011-2019 WhoTrades, Ltd. (http://whotrades.com). All rights reserved.
 */

namespace PhpCsBitBucket\Checker;

use Monolog\Logger;
use PhpCsBitBucket\CheckerResult\CheckerResultItem;
use PhpCsBitBucket\CheckerResult\CheckerResultItemInterface;

class PhpVarDumpCheck implements CheckerInterface
{
    /**
     * @var Logger
     */
    private $log;

    /**
     * @var array
     */
    private $config;

    /**
     * @var string
     */
    private $tmpDir;

    /**
     * @param Logger $log
     * @param array $config
     */
    public function __construct(Logger $log, array $config)
    {
        $this->log = $log;
        $this->config = $config;
        $this->tmpDir = $config['tmpdir'] ?? '/tmp';
    }

    /**
     * @param string $filename
     * @param string $extension
     *
     * @return bool
     */
    public function shouldIgnoreFile($filename, $extension)
    {
        if (!empty($this->config['extensions'])) {
            $checkOnly = explode(',', $this->config['extensions']);

            return !in_array($extension, $checkOnly);
        }

        return false;
    }

    /**
     * @param string $filePath
     *
     * @return \JakubOnderka\PhpVarDumpCheck\Settings
     *
     * @throws \JakubOnderka\PhpVarDumpCheck\Exception\InvalidArgument
     */
    private function getToolConfig(string $filePath)
    {
        $config = [0];
        if (isset($this->config['mode'])) {
            $config[] = $this->config['mode'];
        }

        $settings = \JakubOnderka\PhpVarDumpCheck\Settings::parseArguments(array_merge($config, [$filePath]));

        if (isset($this->config['skipFunctions'])) {
            $skipFunctions = explode(',', $this->config['skipFunctions']);
            foreach ($skipFunctions as $function) {
                if (($key = array_search($function, $settings->functionsToCheck)) !== false) {
                    unset($settings->functionsToCheck[$key]);
                }
            }
        }

        return $settings;
    }

    /**
     * @param string $filename
     * @param string $extension
     * @param string $fileContent
     *
     * @return CheckerResultItemInterface[]
     *
     * @throws \JakubOnderka\PhpVarDumpCheck\Exception\InvalidArgument
     */
    public function processFile($filename, $extension, $fileContent)
    {
        // prepare an temp file for catch
        $tempFile = "$this->tmpDir/temp.$extension";
        file_put_contents($tempFile, $fileContent);
        $result = [];
        try {
            $check = new \JakubOnderka\PhpVarDumpCheck\Manager();
            $status = $check->checkFile($tempFile, $this->getToolConfig($tempFile));
            foreach ($status as $item) {
                $result[] = new CheckerResultItem($item->getLineNumber(), 'Forgotten dump found');
            }
        } catch (\Exception $e) {
            $this->log->addCritical($e->getMessage(), $e->getTrace());
        }

        unlink($tempFile);

        return $result;
    }
}