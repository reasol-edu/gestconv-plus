<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendDailyEmailDigestsMessage;
use App\Service\EmailDigestSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Runs every hour: sends the digests whose hour (per teacher) has arrived. */
#[AsMessageHandler]
final class SendDailyEmailDigestsHandler
{
    public function __construct(private readonly EmailDigestSender $sender) {}

    public function __invoke(SendDailyEmailDigestsMessage $message): void
    {
        $this->sender->sendDue();
    }
}
