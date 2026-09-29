<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Parser;

use Psr\Http\Message\StreamInterface;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\PartHeaderContainer;
use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Proxy\ParserPartProxy;

/**
 * Responsible for creating PartBuilder instances.
 *
 * @author Zaahid Bateson
 */
class PartBuilderFactory
{
    /**
     * Constructs a top-level (message) PartBuilder object and returns it.
     */
    public function newPartBuilder(PartHeaderContainer $headerContainer, StreamInterface $messageStream) : PartBuilder
    {
        return new PartBuilder($headerContainer, $messageStream);
    }

    /**
     * Constructs a child PartBuilder object with the passed $parent as its
     * parent, and returns it.
     */
    public function newChildPartBuilder(PartHeaderContainer $headerContainer, ParserPartProxy $parent) : PartBuilder
    {
        return new PartBuilder(
            $headerContainer,
            null,
            $parent
        );
    }
}
