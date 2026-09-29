<?php
/**
 * @license MIT
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\Laravel\SerializableClosure\Support;

class SelfReference
{
    /**
     * The unique hash representing the object.
     *
     * @var string|int
     */
    public $hash;

    /**
     * Creates a new self reference instance.
     *
     * @param  string|int  $hash
     * @return void
     */
    public function __construct($hash)
    {
        $this->hash = $hash;
    }
}
