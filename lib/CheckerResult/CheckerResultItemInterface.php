<?php
/**
 * @author Artem Naumenko
 *
 * Интерфейсы для проверки файлов разными спрособами
 */
namespace PhpCsBitBucket\CheckerResult;

interface CheckerResultItemInterface
{
    /**
     * Number of line with this error
     * If null - comment to the whole file (for example, if file was renamed)
     * @return int
     */
    public function getAffectedLine() : int;

    /**
     * Text representation of error
     * @return string
     */
    public function getMessage() : string;

    /**
     * If true - robot will disapprove pull request, if false - it is just info message
     * @return bool
     */
    public function isError() : bool;
}
