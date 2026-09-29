<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Header\Consumer;

use Psr\Log\LoggerInterface;
use DavMlm\Vendor\ZBateson\MailMimeParser\Header\Part\MimeTokenPartFactory;

/**
 * GenericConsumerMimeLiteralPartService uses a MimeTokenPartFactory instead
 * of a HeaderPartFactory.
 *
 * @author Zaahid Bateson
 */
class GenericConsumerMimeLiteralPartService extends GenericConsumerService
{
    public function __construct(
        LoggerInterface $logger,
        MimeTokenPartFactory $partFactory,
        CommentConsumerService $commentConsumerService,
        QuotedStringConsumerService $quotedStringConsumerService,
        int $maxHeaderTokenCount = 20000
    ) {
        parent::__construct(
            $logger,
            $partFactory,
            $commentConsumerService,
            $quotedStringConsumerService,
            $maxHeaderTokenCount
        );
    }
}
