<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Parser;

use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Proxy\ParserPartProxyFactory;

/**
 * Provides basic implementations for:
 * - IParser::setParserManager
 * - IParser::getParserMessageProxyFactory (returns $this->parserMessageProxyFactory
 *   which can be set via the default constructor)
 * - IParser::getParserPartProxyFactory (returns $this->parserPartProxyFactory
 *   which can be set via the default constructor)
 *
 * @author Zaahid Bateson
 */
abstract class AbstractParserService implements IParserService
{
    /**
     * @var ParserManagerService the ParserManager, which should call setParserManager
     *      when the parser is added.
     */
    protected ParserManagerService $parserManager;

    public function __construct(
        protected readonly ParserPartProxyFactory $parserMessageProxyFactory,
        protected readonly ParserPartProxyFactory $parserPartProxyFactory,
        protected readonly PartBuilderFactory $partBuilderFactory
    ) {
    }

    public function setParserManager(ParserManagerService $pm) : static
    {
        $this->parserManager = $pm;
        return $this;
    }

    public function getParserMessageProxyFactory() : ParserPartProxyFactory
    {
        return $this->parserMessageProxyFactory;
    }

    public function getParserPartProxyFactory() : ParserPartProxyFactory
    {
        return $this->parserPartProxyFactory;
    }
}
