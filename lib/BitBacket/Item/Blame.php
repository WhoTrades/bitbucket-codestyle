<?php
/**
 * @package PhpCsBitBucket\BitBacket\Item
 */
namespace PhpCsBitBucket\BitBacket\Item;

class Blame
{
    public $lineNumber;
    public $spannedLines;
    public $fileName;
    public $commitId;
    public $commitDisplayId;
    public $author;
    public $authorTimestamp;
    public $committer;
    public $committerTimestamp;

    /**
     * Blame constructor.
     *
     * @param int $lineNumber
     * @param int $spannedLines
     * @param string $fileName
     * @param string $commitId
     * @param string $commitDisplayId
     * @param Person $author
     * @param int $authorTimestamp
     * @param Person $committer
     * @param int $committerTimestamp
     */
    public function __construct(
        int $lineNumber,
        int $spannedLines,
        string $fileName,
        string $commitId,
        string $commitDisplayId,
        Person $author,
        int $authorTimestamp,
        Person $committer,
        int $committerTimestamp
    ) {
        $this->lineNumber = $lineNumber;
        $this->spannedLines = $spannedLines;
        $this->fileName = $fileName;

        $this->commitId = $commitId;
        $this->commitDisplayId = $commitDisplayId;
        $this->author = $author;
        $this->authorTimestamp = $authorTimestamp;
        $this->committer = $committer;
        $this->committerTimestamp = $committerTimestamp;
    }

    /**
     * @return int
     */
    public function getLastSpannedLineNumber()
    {
        return $this->lineNumber + $this->spannedLines - 1;
    }
}
