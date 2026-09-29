<?php

/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Proxy;

use Psr\Log\LoggerInterface;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\Factory\PartHeaderContainerFactory;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\Helper\MultipartHelper;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\Helper\PrivacyHelper;
use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\IParserService;
use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Part\ParserPartChildrenContainerFactory;
use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Part\ParserPartStreamContainerFactory;
use DavMlm\Vendor\ZBateson\MailMimeParser\Parser\PartBuilder;
use DavMlm\Vendor\ZBateson\MailMimeParser\Stream\StreamFactory;

/**
 * Responsible for creating proxied IMessage instances wrapped in a
 * ParserMessageProxy.
 *
 * @author Zaahid Bateson
 */
class ParserMessageProxyFactory extends ParserMimePartProxyFactory
{
    public function __construct(
        LoggerInterface $logger,
        StreamFactory $streamFactory,
        PartHeaderContainerFactory $partHeaderContainerFactory,
        ParserPartStreamContainerFactory $parserPartStreamContainerFactory,
        ParserPartChildrenContainerFactory $parserPartChildrenContainerFactory,
        protected readonly MultipartHelper $multipartHelper,
        protected readonly PrivacyHelper $privacyHelper,
        string $defaultFallbackCharset = 'ISO-8859-1'
    ) {
        parent::__construct($logger, $streamFactory, $partHeaderContainerFactory, $parserPartStreamContainerFactory, $parserPartChildrenContainerFactory, $defaultFallbackCharset);
    }

    /**
     * Constructs a new ParserMessageProxy wrapping an IMessage object that will
     * dynamically parse a message's content and parts as they're requested.
     */
    public function newInstance(PartBuilder $partBuilder, IParserService $parser) : ParserMessageProxy
    {
        $parserProxy = new ParserMessageProxy($partBuilder, $parser);

        // parsed before the part's header container copies the parser's header
        // objects, so the part reuses it rather than parsing it a second time
        $parserProxy->getContentType();

        $streamContainer = $this->parserPartStreamContainerFactory->newInstance($parserProxy);
        $headerContainer = $this->partHeaderContainerFactory->newInstance($parserProxy->getHeaderContainer());
        $childrenContainer = $this->parserPartChildrenContainerFactory->newInstance($parserProxy);

        $message = new Message(
            $this->logger,
            $streamContainer,
            $headerContainer,
            $childrenContainer,
            $this->multipartHelper,
            $this->privacyHelper,
            $this->defaultFallbackCharset
        );
        $parserProxy->setPart($message);

        $streamContainer->setStream($this->streamFactory->newMessagePartStream($message));
        $message->attach($streamContainer);
        return $parserProxy;
    }
}
