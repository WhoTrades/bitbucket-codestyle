<?php
/**
 * @author Artem Naumenko
 *
 * Интерфейсы для проверки файлов разными спрособами
 */
namespace PhpCsBitBucket\CheckerResult;

class CheckerResultItem implements CheckerResultItemInterface
{
    /**
     * @var int
     */
    private $line;

    /**
     * @var string
     */
    private $message;

    /**
     * @var bool
     */
    private $error;

    /**
     * CheckerResultItem constructor.
     * @param int $line - line of error. zero - if general error at file (for example - moving file to invalid place)
     * @param string $message
     * @param bool $error
     */
    public function __construct(int $line, string $message, bool $error = true)
    {
        $this->line = $line;
        $this->message = $message;
        $this->error = $error;
    }

    /**
     * {@inheritdoc}
     */
    public function getAffectedLine(): int
    {
        return $this->line;
    }

    /**
     * {@inheritdoc}
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * {@inheritdoc}
     */
    public function isError(): bool
    {
        return $this->error;
    }
}
