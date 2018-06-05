<?php
/**
 * @package PhpCsBitBucket\BitBacket\Item
 */
namespace PhpCsBitBucket\BitBacket\Item;

class Commit
{
    public $id;
    public $displayId;
    public $author;
    public $authorTimestamp;
    public $committer;
    public $committerTimestamp;
    public $message;

    /**
     * Commit constructor.
     * @param string $id
     * @param string $displayId
     * @param Person $author
     * @param int $authorTimestamp
     * @param Person $committer
     * @param int $committerTimestamp
     * @param string $message
     */
    public function __construct(string $id, string $displayId, Person $author, int $authorTimestamp, Person $committer, int $committerTimestamp, string $message)
    {
        $this->id = $id;
        $this->displayId = $displayId;
        $this->author = $author;
        $this->authorTimestamp = $authorTimestamp;
        $this->committer = $committer;
        $this->committerTimestamp = $committerTimestamp;
        $this->message = $message;
    }
}
