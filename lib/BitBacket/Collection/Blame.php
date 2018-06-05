<?php
/**
 * @package PhpCsBitBucket\BitBacket\Collection
 */
namespace PhpCsBitBucket\BitBacket\Collection;

use \PhpCsBitBucket\BitBacket\Item\Blame as BlameItem;
use \PhpCsBitBucket\BitBacket\Exception\BlameDuplicateLineException;
use \PhpCsBitBucket\BitBacket\Exception\LineNotExistException;

class Blame
{
    /**
     * @var BlameItem[]
     */
    protected $dataCollection = [];

    /**
     * @var string
     */
    protected $currentFileName;

    /**
     * Blame constructor.
     *
     * @param string $realFileName
     */
    public function __construct($realFileName)
    {
        $this->currentFileName = $realFileName;
    }

    /**
     * @param BlameItem $item
     *
     * @throws BlameDuplicateLineException
     */
    public function addItem(BlameItem $item)
    {
        $lineNumberNewItem = $item->lineNumber;
        $spannedLinesNewItem = $item->spannedLines;

        // ag: Check for duplicated blames of same line
        for ($lineNumber = $item->lineNumber; $lineNumber <= $item->getLastSpannedLineNumber(); $lineNumber++) {
            if (array_key_exists($lineNumber, $this->dataCollection)) {
                throw new BlameDuplicateLineException(sprintf('Duplicate blame of line %s in file %s', $lineNumber, $this->currentFileName));
            }
        }

        $this->dataCollection[$item->lineNumber] = $item;
    }

    /**
     * @param int $lineNumber
     *
     * @return BlameItem
     *
     * @throws LineNotExistException
     */
    public function getBlameByLineNumber($lineNumber)
    {
        $arrayKeys = array_keys($this->dataCollection);

        $lastBlameLineNumber = max($arrayKeys);
        $lastBlame = $this->dataCollection[$lastBlameLineNumber];

        if ($lineNumber > $lastBlame->getLastSpannedLineNumber()) {
            throw new LineNotExistException(sprintf('Line %s in not exist in file %s', $lineNumber, $this->currentFileName));
        }

        while (count($arrayKeys)) {
            $middleLineKey = (int) floor(count($arrayKeys) / 2);
            $middleLineValue = $arrayKeys[$middleLineKey];

            $blame = $this->dataCollection[$middleLineValue];

            if ($lineNumber >= $blame->lineNumber && $lineNumber <= $blame->getLastSpannedLineNumber()) {
                return $blame;
            }

            if ($lineNumber < $blame->lineNumber) {
                $arrayKeys = array_slice($arrayKeys, 0, $middleLineKey);
            } else {
                $arrayKeys = array_slice($arrayKeys, $middleLineKey);
            }
        }
    }
}
