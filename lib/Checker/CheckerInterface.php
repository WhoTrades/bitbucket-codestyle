<?php
/**
 * @author Evgeny Sisoev
 *
 * Интерфейсы для проверки файлов разными спрособами
 */
namespace PhpCsBitBucket\Checker;

use PhpCsBitBucket\CheckerResult\CheckerResultItemInterface;

interface CheckerInterface
{
    /**
     * @param string $filename
     * @param string $extension
     * @return bool
     */
    public function shouldIgnoreFile($filename, $extension);
    
    /**
     * @param string $filename
     * @param string $extension
     * @param string $fileContent
     * @return CheckerResultItemInterface[]
     */
    public function processFile($filename, $extension, $fileContent);
}
