<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\HeartbeatMessage;
use App\Service\Backup\RestoreState;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Mantiene al worker alejado de la base de datos mientras se restaura una copia de seguridad. La web responde 503 entretanto
 * (RestoreGuardSubscriber), pero el worker es otro proceso: restaurar una copia de una versión anterior borra todas las tablas y
 * las vuelve a migrar, y una tarea programada (purgas, avisos, recordatorios) o un correo que escribiera a mitad fallaría o,
 * peor, escribiría en un esquema a medias.
 *
 * Solo se retiene lo que recibe el worker: un mensaje enviado desde la web o la consola pasa. Un turno del planificador se salta
 * (la misma tarea vuelve a salir en el siguiente, así que no se pierde nada) y cualquier otro mensaje se devuelve a la cola para
 * reintentarlo más tarde, sin gastar sus reintentos. La señal de vida del worker (HeartbeatMessage) sí se deja pasar: solo escribe
 * un fichero, y si se saltara, una restauración larga haría que Administración diera por caído un worker que está bien.
 */
final class RestoreLockMiddleware implements MiddlewareInterface
{
    /** Milisegundos hasta que se vuelve a intentar un mensaje retenido. */
    private const RETRY_DELAY = 30_000;

    public function __construct(private readonly RestoreState $state) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class);
        if ($received === null || $envelope->getMessage() instanceof HeartbeatMessage || !$this->state->isRunning()) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (str_starts_with($received->getTransportName(), 'scheduler_')) {
            return $envelope;
        }

        throw new RecoverableMessageHandlingException('Se está restaurando una copia de seguridad: el mensaje espera.', 0, null, self::RETRY_DELAY);
    }
}
