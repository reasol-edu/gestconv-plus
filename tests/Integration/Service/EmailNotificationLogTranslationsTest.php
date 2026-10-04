<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * El registro de envío de correos muestra el evento de cada aviso: cada evento que puede enviarse
 * (los que tienen asunto en emails.es.yaml, salvo los que no pasan por el registro) debe tener etiqueta.
 */
class EmailNotificationLogTranslationsTest extends KernelTestCase
{
    /** Correos que no se envían desde IncidentEmailNotifier y por tanto no quedan en el registro. */
    private const NOT_LOGGED = ['password_reset', 'email_verification'];

    public function testEveryLoggedEmailEventHasALabel(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorBagInterface);
        $catalogue = $translator->getCatalogue('es');

        $events = [];
        foreach (array_keys($catalogue->all('emails')) as $id) {
            if (preg_match('/^emails\.([a-z_]+)\.subject$/', (string) $id, $m) && !in_array($m[1], self::NOT_LOGGED, true)) {
                $events[] = $m[1];
            }
        }

        self::assertGreaterThan(10, count($events), 'No se han encontrado eventos de correo en emails.es.yaml');
        $missing = array_values(array_filter($events, static fn (string $e): bool => !$catalogue->has('email_notification_log.event.' . $e, 'admin')));
        self::assertSame([], $missing, 'Eventos de correo sin etiqueta en translations/admin.es.yaml (email_notification_log.event.*)');
    }
}
