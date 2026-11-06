<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Issue;
use App\Entity\ResourceBooking;
use App\Service\BookingIssues;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `booking_issues(booking)`: las incidencias de una reserva que puede ver el usuario actual (para sus marcas). */
final class BookingIssuesExtension extends AbstractExtension
{
    public function __construct(
        private readonly BookingIssues $bookingIssues,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('booking_issues', fn (ResourceBooking $booking): array => $this->bookingIssues->visibleFor($booking)),
            new TwigFunction('booking_has_open_issue', fn (ResourceBooking $booking): bool => $this->bookingIssues->hasOpen($booking)),
        ];
    }
}
