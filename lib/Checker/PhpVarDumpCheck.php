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

    public function __construct(Logger $log, array $config)
    {
        $this->log = $log;
        $this->config = $config;
        $this->tmpDir = $config['tmpdir'];
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
            $settings = \JakubOnderka\PhpVarDumpCheck\Settings::parseArguments([0, $this->config['mode'], "{$tempFile}"]);
            $check = new \JakubOnderka\PhpVarDumpCheck\Manager();
            $status = $check->checkFile($tempFile, $settings);
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