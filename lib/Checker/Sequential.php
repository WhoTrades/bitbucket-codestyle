<?php
/**
 * @author Artem Naumenko
 *
 * Checker that sequentially checks by many other checkers
 */
namespace PhpCsBitBucket\Checker;

use PhpCsBitBucket\CheckerResult\CheckerResultItemInterface;

class Sequential implements CheckerInterface
{
    /**
     * @var CheckerInterface[]
     */
    private $checkers = [];

    /**
     * Sequential constructor.
     * @param CheckerInterface[] $checkers
     */
    public function __construct(array $checkers)
    {
        $this->checkers = $checkers;
    }

    /**
     * @param string $filename
     * @param string $extension
     * @return bool
     */
    public function shouldIgnoreFile($filename, $extension)
    {
        foreach ($this->checkers as $checker) {
            if (!$checker->shouldIgnoreFile(...func_get_args())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $filename
     * @param string $extension
     * @param string $fileContent
     * @return CheckerResultItemInterface[]
     */
    public function processFile($filename, $extension, $fileContent)
    {
        $result = [];
        foreach ($this->checkers as $checker) {
            if (!$checker->shouldIgnoreFile($filename, $extension)) {
                $currentCheckerResult = $checker->processFile(...func_get_args());
                $result = array_merge($result, $currentCheckerResult);
            }
        }

        return $result;
    }
}