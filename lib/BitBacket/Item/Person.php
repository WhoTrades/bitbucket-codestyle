<?php
/**
 * @package PhpCsBitBucket\BitBacket\Item
 */
namespace PhpCsBitBucket\BitBacket\Item;

class Person
{
    public $id;
    public $userName;
    public $email;
    public $displayName;

    /**
     * @param int | null $id
     * @param string $userName
     * @param string $email
     * @param string | null $displayName
     */
    public function __construct(int $id = null, string $userName, string $email, string $displayName = null)
    {
        $this->id = $id;
        $this->userName = $userName;
        $this->email = $email;
        $this->displayName = $displayName;
    }
}
