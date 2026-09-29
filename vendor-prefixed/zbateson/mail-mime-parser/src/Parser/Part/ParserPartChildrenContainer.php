<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Part;

use DavMlm\Vendor\ZBateson\MailMimeParser\Message\PartChildrenContainer;
use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Proxy\ParserMimePartProxy;

/**
 * A child container that proxies calls to a parser when attempting to access
 * child parts.
 *
 * @author Zaahid Bateson
 */
class ParserPartChildrenContainer extends PartChildrenContainer
{
    /**
     * @var bool Set to true once all parts have been parsed, and requests to
     *      the proxy won't result in any more child parts.
     */
    private bool $allParsed = false;

    public function __construct(protected readonly ParserMimePartProxy $parserProxy)
    {
        parent::__construct([]);
    }

    public function offsetExists($offset) : bool
    {
        $exists = parent::offsetExists($offset);
        while (!$exists && !$this->allParsed) {
            $child = $this->parserProxy->popNextChild();
            if ($child === null) {
                $this->allParsed = true;
            } else {
                $this->add($child);
            }
            $exists = parent::offsetExists($offset);
        }
        return $exists;
    }
}
