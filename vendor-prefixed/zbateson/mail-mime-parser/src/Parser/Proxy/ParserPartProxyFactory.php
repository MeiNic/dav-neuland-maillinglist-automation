<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Proxy;

use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\IParserService;
use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\PartBuilder;

/**
 * Base class for factories creating ParserPartProxy classes.
 *
 * @author Zaahid Bateson
 */
abstract class ParserPartProxyFactory
{
    /**
     * Constructs a new ParserPartProxy wrapping an IMessagePart object.
     *
     */
    abstract public function newInstance(PartBuilder $partBuilder, IParserService $parser) : ParserPartProxy;
}
