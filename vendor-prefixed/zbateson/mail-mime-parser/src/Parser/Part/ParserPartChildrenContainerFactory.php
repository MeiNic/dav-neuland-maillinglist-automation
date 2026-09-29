<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Part;

use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Proxy\ParserMimePartProxy;

/**
 * Creates ParserPartChildrenContainer instances.
 *
 * @author Zaahid Bateson
 */
class ParserPartChildrenContainerFactory
{
    public function newInstance(ParserMimePartProxy $parserProxy) : ParserPartChildrenContainer
    {
        return new ParserPartChildrenContainer($parserProxy);
    }
}
