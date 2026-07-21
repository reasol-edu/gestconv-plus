<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ObservationInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CRUD común de observaciones de partes y sanciones: validación de la
 * edición (texto y, con permiso, fecha), aplicación con seguimiento de
 * cambios y persistencia.
 */
class ObservationFormHandler
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['text', 'registeredAt'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntityChangeTracker $changeTracker,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @param string $keyPrefix p. ej. "incident.observation" o "sanction.observation"
     *
     * @return array{errors: array<string, string>, registeredAt: ?\DateTimeImmutable, text: string}
     */
    public function validateEdit(
        Request $request,
        ObservationInterface $observation,
        bool $canEditDate,
        string $keyPrefix,
    ): array {
        $errors = [];
        $text   = trim($request->request->getString('text'));

        $registeredAt = $observation->getRegisteredAt();
        if ($canEditDate) {
            $registeredAtRaw = trim($request->request->getString('registered_at'));

            $registeredAt = null;
            if ($registeredAtRaw !== '') {
                try {
                    $registeredAt = new \DateTimeImmutable($registeredAtRaw);
                } catch (\Exception) {
                    $registeredAt = null;
                }
            }

            if ($registeredAt === null) {
                $errors['registered_at'] = $this->t($keyPrefix . '.error.invalid');
            }
        }

        if ($text === '') {
            $errors['text'] = $this->t($keyPrefix . '.error.invalid');
        }

        return ['errors' => $errors, 'registeredAt' => $registeredAt, 'text' => $text];
    }

    /**
     * @return array<string, array{before: mixed, after: mixed}> cambios efectivos
     */
    public function applyEdit(ObservationInterface $observation, \DateTimeImmutable $registeredAt, string $text): array
    {
        $before = $this->changeTracker->snapshot($observation, self::LOGGED_FIELDS);

        $observation->setRegisteredAt($registeredAt)->setText($text);
        $this->em->flush();

        return $this->changeTracker->diff($before, $observation, self::LOGGED_FIELDS);
    }

    public function create(ObservationInterface $observation): void
    {
        $this->em->persist($observation);
        $this->em->flush();
    }

    public function delete(ObservationInterface $observation): void
    {
        $this->em->remove($observation);
        $this->em->flush();
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
