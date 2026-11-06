<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssueAttachment;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\LocationOption;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\Teacher;
use App\Repository\IssuePriorityRepository;
use App\Repository\IssueRepository;
use App\Repository\IssueTypeRepository;
use App\Repository\LocationOptionRepository;
use App\Repository\ResourceBookingRepository;
use App\Security\Voter\IssueVoter;
use App\Security\Voter\ResourceBookingVoter;
use App\Service\AttachmentDownloadResponder;
use App\Service\IssueAssignees;
use App\Service\IssueAttachmentFactory;
use App\Service\IssueManager;
use App\Service\PdfHeaderBuilder;
use App\Service\PdfRenderer;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Incidencias: alta (también desde una reserva), ficha con observaciones, adjuntos e historial, y su gestión por los
 * responsables (tipo, dependencia, prioridad y estado).
 */
#[Route('/incidencias')]
class IssueController extends AbstractController
{
    use PastYearGuardTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly IssueRepository $issues,
        private readonly IssueTypeRepository $types,
        private readonly IssuePriorityRepository $priorities,
        private readonly LocationOptionRepository $locations,
        private readonly ResourceBookingRepository $bookings,
        private readonly IssueManager $manager,
        private readonly IssueAssignees $assignees,
        private readonly PdfRenderer $pdfRenderer,
        private readonly PdfHeaderBuilder $pdfHeaderBuilder,
        private readonly IssueAttachmentFactory $attachmentFactory,
        private readonly AttachmentDownloadResponder $downloads,
    ) {}

    // ── Listado ───────────────────────────────────────────────────────────────

    /** Listado (componente Live): las incidencias que el usuario puede ver. */
    #[Route('', name: 'app_issues_index', methods: ['GET'])]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(IssueVoter::CREATE, $centre);

        return $this->render('issue/index.html.twig', ['centre' => $centre]);
    }

    // ── Alta ──────────────────────────────────────────────────────────────────

    /**
     * Nueva incidencia. Con `?booking=` (solo desde la ficha de una reserva, y solo el docente que la hizo) la incidencia
     * se asocia a esa reserva, que ya incluye el recurso: no se pide la dependencia.
     */
    #[Route('/nueva', name: 'app_issues_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(IssueVoter::CREATE, $centre);
        $this->denyIfViewingPastYear($centre);
        $year  = $this->requireYear($centre);
        $actor = $this->requireTeacher();

        $booking = $this->usableBooking($request->query->getString('booking') ?: $request->request->getString('booking'), $centre, $year);
        $values  = ['type' => $request->query->getString('type'), 'location' => '', 'description' => ''];
        $errors  = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_issue', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'type'        => $request->request->getString('type'),
                'location'    => $request->request->getString('location'),
                'description' => $request->request->getString('description'),
            ];

            $type = $this->selectableType($centre, $values['type']);
            if ($type === null) {
                $errors['type'] = 'issue.error.type_required';
            }
            if ($this->isBlank($values['description'])) {
                $errors['description'] = 'issue.error.description_required';
            }
            $location = null;
            if ($booking === null && $values['location'] !== '') {
                $location = $this->locations->findById($values['location']);
                // Solo las ubicaciones del centro disponibles para todos los sitios (no las «solo para partes»).
                if ($location === null || !$location->getEducationalCentre()->getId()->equals($centre->getId()) || !$location->getCategory()->isForAllPlaces()) {
                    $location = null;
                }
            }

            // Los adjuntos se validan antes de guardar nada.
            $attachmentErrors = $this->attachmentFactory->errors($request->files->all('attachments'));
            if ($attachmentErrors !== []) {
                $errors['attachments'] = implode(' ', $attachmentErrors);
            }

            if ($errors === []) {
                $issue = $this->manager->create($centre, $year, $actor, $type, $location, $booking, trim($values['description']), []);
                // Los adjuntos se asocian a la incidencia ya creada.
                $built = $this->attachmentFactory->build($issue, $actor, $request->files->all('attachments'), $this->clockNow());
                $this->manager->addAttachments($issue, $actor, $built['attachments']);

                $this->addFlash('success', $this->t('issue.flash.created'));

                return $this->redirectToRoute('app_issues_show', ['id' => $issue->getId()->toRfc4122()]);
            }
        }

        return $this->render('issue/new.html.twig', [
            'centre'    => $centre,
            'values'    => $values,
            'errors'    => $errors,
            'booking'   => $booking,
            'types'     => $this->selectableTypes($centre),
            'locations' => $this->locations->findByCentreActive($centre, true),
        ]);
    }

    // ── Ficha ─────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'app_issues_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireIssue($id, $centre);
        $this->denyAccessUnlessGranted(IssueVoter::VIEW, $issue);

        // Quien gestiona lo cambia todo; la persona asignada, solo la prioridad y el estado.
        $canManage = $this->isGranted(IssueVoter::MANAGE, $issue);
        $canAct    = $this->isGranted(IssueVoter::ACT, $issue);

        return $this->render('issue/show.html.twig', [
            'centre'     => $centre,
            'issue'      => $issue,
            'canManage'  => $canManage,
            'canAct'     => $canAct,
            'canEdit'    => $this->isGranted(IssueVoter::EDIT, $issue),
            'canDelete'  => $this->isGranted(IssueVoter::DELETE, $issue),
            'candidates' => $canAct ? $this->issues->findDuplicateCandidates($centre, $issue) : [],
            'priorities' => $canAct ? $this->priorities->findByCentreOrdered($centre) : [],
            'types'      => $canManage ? $this->selectableTypes($centre, $issue->getType()) : [],
            'locations'  => $canManage ? $this->locations->findByCentreActive($centre, true) : [],
            'assignees'  => $canManage ? $this->assignees->candidates($centre) : [],
            'today'      => $this->clockNow(),
        ]);
    }

    // ── Observaciones ─────────────────────────────────────────────────────────

    /**
     * Añade una observación. Quien gestiona la incidencia puede, desde esta misma pantalla, cambiar su estado (cerrarla,
     * ponerla en proceso o reabrirla).
     */
    #[Route('/{id}/observacion', name: 'app_issues_observe', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function observe(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireWritableIssue($id, $centre, $request, 'observe_issue_');
        $this->denyAccessUnlessGranted(IssueVoter::VIEW, $issue);
        $actor = $this->requireTeacher();

        $content = trim($request->request->getString('content'));
        if ($content === '') {
            $this->addFlash('error', $this->t('issue.error.observation_required'));

            return $this->redirectToRoute('app_issues_show', ['id' => $id]);
        }

        // El cambio de estado solo lo puede pedir quien gestiona o tiene asignada la incidencia; si falla, la observación no se guarda.
        $status = IssueStatus::tryFrom($request->request->getString('status'));
        if ($status !== null && $this->isGranted(IssueVoter::ACT, $issue)) {
            $error = $this->manager->transition($issue, $actor, $status, $this->duplicateFrom($request, $centre));
            if ($error !== null) {
                $this->addFlash('error', $this->t($error));

                return $this->redirectToRoute('app_issues_show', ['id' => $id]);
            }
        }

        $this->manager->addObservation($issue, $actor, mb_substr($content, 0, 5000));
        $this->addFlash('success', $this->t('issue.flash.observed'));

        return $this->redirectToRoute('app_issues_show', ['id' => $id]);
    }

    // ── Cambios ───────────────────────────────────────────────────────────────

    /** Cambia lo ocurrido (la descripción): quien la registró mientras esté abierta, y quien la gestiona. */
    #[Route('/{id}/descripcion', name: 'app_issues_edit', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function editDescription(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireWritableIssue($id, $centre, $request, 'edit_issue_');
        $this->denyAccessUnlessGranted(IssueVoter::EDIT, $issue);

        $description = $request->request->getString('description');
        if ($this->isBlank($description)) {
            $this->addFlash('error', $this->t('issue.error.description_required'));
        } elseif ($this->manager->updateDescription($issue, $this->requireTeacher(), trim($description))) {
            $this->addFlash('success', $this->t('issue.flash.updated'));
        }

        return $this->redirectToRoute('app_issues_show', ['id' => $id]);
    }

    /**
     * Gestión: los responsables y la dirección cambian el tipo, la dependencia, la persona asignada, la prioridad y el
     * estado; la persona asignada, solo la prioridad y el estado.
     */
    #[Route('/{id}/gestionar', name: 'app_issues_manage', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function manage(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireWritableIssue($id, $centre, $request, 'manage_issue_');
        $this->denyAccessUnlessGranted(IssueVoter::ACT, $issue);
        $actor = $this->requireTeacher();
        $full  = $this->isGranted(IssueVoter::MANAGE, $issue);

        $errors = [];

        $type = $full ? $this->selectableType($centre, $request->request->getString('type'), $issue->getType()) : null;
        if ($type !== null) {
            $this->manager->changeType($issue, $actor, $type);
        }

        // Asignada a: vacío la deja sin asignar; solo se acepta a quien se le puede asignar (o a quien ya la tiene).
        if ($full && $request->request->has('assignee')) {
            $assigneeId = $request->request->getString('assignee');
            $assignee   = $assigneeId !== '' ? $this->assignees->find($centre, $assigneeId) : null;
            $keeps      = $assigneeId !== '' && $issue->getAssignee()?->getId()->toRfc4122() === strtolower($assigneeId);
            if ($assigneeId === '' || $assignee !== null || $keeps) {
                $this->manager->changeAssignee($issue, $actor, $assigneeId === '' ? null : ($assignee ?? $issue->getAssignee()));
            }
        }

        if ($full && $issue->getBooking() === null) {
            $locationId = $request->request->getString('location');
            $location   = $locationId !== '' ? $this->locations->findById($locationId) : null;
            // Se puede dejar la actual (aunque sea de una categoría «solo para partes») o elegir una disponible para todos los sitios.
            $keepsCurrent = $location !== null && $issue->getLocation()?->getId()->equals($location->getId()) === true;
            if ($locationId === '' || ($location !== null && $location->getEducationalCentre()->getId()->equals($centre->getId()) && ($keepsCurrent || $location->getCategory()->isForAllPlaces()))) {
                $this->manager->changeLocation($issue, $actor, $location);
            }
        }

        $priorityId = $request->request->getString('priority');
        $priority   = $priorityId !== '' ? $this->priorities->findByCentreAndId($centre, $priorityId) : null;
        if ($priorityId === '' || $priority !== null) {
            $errors[] = $this->manager->setPriority($issue, $actor, $priority);
        }

        $status = IssueStatus::tryFrom($request->request->getString('status'));
        if ($status !== null) {
            $errors[] = $this->manager->transition($issue, $actor, $status, $this->duplicateFrom($request, $centre));
        }

        $errors = array_values(array_unique(array_filter($errors)));
        foreach ($errors as $error) {
            $this->addFlash('error', $this->t($error));
        }
        if ($errors === []) {
            $this->addFlash('success', $this->t('issue.flash.managed'));
        }

        return $this->redirectToRoute('app_issues_show', ['id' => $id]);
    }

    // ── Adjuntos ──────────────────────────────────────────────────────────────

    #[Route('/{id}/adjuntos', name: 'app_issues_attach', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function attach(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireWritableIssue($id, $centre, $request, 'attach_issue_');
        $this->denyAccessUnlessGranted(IssueVoter::VIEW, $issue);
        $actor = $this->requireTeacher();

        $built = $this->attachmentFactory->build($issue, $actor, $request->files->all('attachments'), $this->clockNow());
        foreach ($built['errors'] as $error) {
            $this->addFlash('error', $error);
        }
        if ($built['attachments'] !== []) {
            $this->manager->addAttachments($issue, $actor, $built['attachments']);
            $this->addFlash('success', $this->t('issue.flash.attached'));
        }

        return $this->redirectToRoute('app_issues_show', ['id' => $id]);
    }

    /** Descarga un adjunto; con `?miniatura=1`, la miniatura de una imagen. */
    #[Route('/{id}/adjuntos/{attachmentId}', name: 'app_issues_attachment', requirements: ['id' => '[0-9a-fA-F-]{36}', 'attachmentId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function attachment(string $id, string $attachmentId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireIssue($id, $centre);
        $this->denyAccessUnlessGranted(IssueVoter::VIEW, $issue);
        $attachment = $this->requireAttachment($issue, $attachmentId);

        if ($request->query->getBoolean('miniatura') && $attachment->getThumbnail() !== null) {
            return $this->inline($attachment->getThumbnail(), 'image/jpeg');
        }

        // Las imágenes de la lista cerrada se ven en línea; el resto se descarga.
        if (in_array($attachment->getMimeType(), IssueAttachmentFactory::IMAGE_TYPES, true) && $request->query->getBoolean('ver')) {
            return $this->inline($attachment->getContent(), $attachment->getMimeType());
        }

        return $this->downloads->respond($attachment->getContent(), $attachment->getMimeType(), $attachment->getFilename());
    }

    #[Route('/{id}/adjuntos/{attachmentId}/eliminar', name: 'app_issues_attachment_delete', requirements: ['id' => '[0-9a-fA-F-]{36}', 'attachmentId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function deleteAttachment(string $id, string $attachmentId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireWritableIssue($id, $centre, $request, 'delete_issue_attachment_');
        $this->denyAccessUnlessGranted(IssueVoter::VIEW, $issue);
        $actor      = $this->requireTeacher();
        $attachment = $this->requireAttachment($issue, $attachmentId);

        // Lo quita quien lo subió (si la incidencia sigue abierta) o quien la gestiona.
        $own = $attachment->getUploadedBy()?->getId()->equals($actor->getId()) ?? false;
        if (!$this->isGranted(IssueVoter::MANAGE, $issue) && !($own && $issue->isOpen())) {
            throw $this->createAccessDeniedException();
        }

        $this->manager->removeAttachment($issue, $actor, $attachment);
        $this->addFlash('success', $this->t('issue.flash.attachment_removed'));

        return $this->redirectToRoute('app_issues_show', ['id' => $id]);
    }

    // ── Informe PDF ───────────────────────────────────────────────────────────

    /** Informe en PDF con todos los datos de la incidencia, sus observaciones y su historial (quien puede verla). */
    #[Route('/{id}/informe', name: 'app_issues_report', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function report(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireIssue($id, $centre);
        $this->denyAccessUnlessGranted(IssueVoter::VIEW, $issue);

        $thumbnails = [];
        foreach ($issue->getAttachments() as $attachment) {
            $thumbnail = $attachment->getThumbnail();
            if ($thumbnail !== null) {
                $thumbnails[$attachment->getId()->toRfc4122()] = base64_encode($thumbnail);
            }
        }

        $title        = $this->t('issue.pdf.title');
        $placeholders = [
            'title'         => $title,
            'issue_nr'      => $issue->getNumber(),
            'issue_type'    => $issue->getType()->getPath(),
            'centre_name'   => $centre->getName(),
            'academic_year' => $issue->getAcademicYear()->getName(),
        ];

        return $this->pdfRenderer->render(
            'pdf/issue.html.twig',
            [
                'centre'     => $centre,
                'issue'      => $issue,
                'overdue'    => $issue->isOverdue($this->clockNow()),
                'thumbnails' => $thumbnails,
                'footerHtml' => $this->pdfHeaderBuilder->buildFooter('issue', $centre, $placeholders),
            ],
            $title . ' #' . $issue->getNumber(),
            sprintf('incidencia-%d.pdf', $issue->getNumber()),
            inline: !$request->query->getBoolean('descargar'),
            header: $this->pdfHeaderBuilder->build('issue', $centre, $placeholders),
            centre: $centre,
            reportType: 'issue',
        );
    }

    // ── Eliminación ───────────────────────────────────────────────────────────

    /** Solo la dirección: elimina la incidencia con todo lo suyo. */
    #[Route('/{id}/eliminar', name: 'app_issues_delete', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $issue = $this->requireWritableIssue($id, $centre, $request, 'delete_issue_');
        $this->denyAccessUnlessGranted(IssueVoter::DELETE, $issue);

        $number = $issue->getNumber();
        $this->manager->delete($issue);
        $this->addFlash('success', $this->translator->trans('issue.flash.deleted', ['%number%' => $number], 'admin'));

        return $this->redirectToRoute('app_issues_index');
    }

    // ── Ayudas ────────────────────────────────────────────────────────────────

    private function inline(string $content, string $mimeType): Response
    {
        $response = new Response($content);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }

    /** La incidencia, del centro seleccionado; si no, 404. */
    private function requireIssue(string $id, EducationalCentre $centre): Issue
    {
        return $this->issues->findByCentreAndId($centre, $id) ?? throw $this->createNotFoundException();
    }

    /** Para las operaciones que escriben: la incidencia, con el token CSRF comprobado y sin estar viendo un curso pasado. */
    private function requireWritableIssue(string $id, EducationalCentre $centre, Request $request, string $tokenPrefix): Issue
    {
        $issue = $this->requireIssue($id, $centre);
        $this->denyIfViewingPastYear($centre);
        if (!$this->isCsrfTokenValid($tokenPrefix . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return $issue;
    }

    private function requireAttachment(Issue $issue, string $attachmentId): IssueAttachment
    {
        foreach ($issue->getAttachments() as $attachment) {
            if ($attachment->getId()->toRfc4122() === strtolower($attachmentId)) {
                return $attachment;
            }
        }

        throw $this->createNotFoundException();
    }

    /** La incidencia original que se indica al cerrar por duplicidad: «#12» o «12», del mismo centro. */
    private function duplicateFrom(Request $request, EducationalCentre $centre): ?Issue
    {
        $raw = ltrim(trim($request->request->getString('duplicate')), '#');

        return ctype_digit($raw) ?$this->issues->findByCentreAndNumber($centre, (int) $raw) : null;
    }

    /** Un tipo activo del centro (o el actual de la incidencia, aunque ya esté inactivo). */
    private function selectableType(EducationalCentre $centre, string $id, ?IssueType $current = null): ?IssueType
    {
        $type = $id !== '' ? $this->types->findByCentreAndId($centre, $id) : null;
        if ($type === null) {
            return null;
        }

        return $type->isActive() || ($current !== null && $current->getId()->equals($type->getId())) ? $type : null;
    }

    /**
     * Los tipos que se pueden elegir, con la ruta completa, ordenados por ella.
     *
     * @return list<IssueType>
     */
    private function selectableTypes(EducationalCentre $centre, ?IssueType $current = null): array
    {
        $types = array_values(array_filter(
            $this->types->findByCentre($centre),
            static fn (IssueType $type): bool => $type->isActive() || ($current !== null && $current->getId()->equals($type->getId())),
        ));
        usort($types, static fn (IssueType $a, IssueType $b): int => strcmp(mb_strtolower($a->getPath()), mb_strtolower($b->getPath())));

        return $types;
    }

    /** La reserva de la que nace la incidencia, solo si es del docente, del centro y del curso, y está aprobada. */
    private function usableBooking(string $id, EducationalCentre $centre, AcademicYear $year): ?ResourceBooking
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $booking = $this->bookings->findById($id);
        if (
            $booking === null
            || !$booking->getResource()->getCategory()->getEducationalCentre()->getId()->equals($centre->getId())
            || !$booking->getAcademicYear()->getId()->equals($year->getId())
            || $booking->getStatus() !== ResourceBookingStatus::Approved
            // Quien hizo la reserva, los responsables de su categoría y la dirección.
            || !$this->isGranted(ResourceBookingVoter::REPORT_ISSUE, $booking)
        ) {
            return null;
        }

        return $booking;
    }

    /** El texto enriquecido está vacío (solo etiquetas, espacios o saltos). */
    private function isBlank(string $html): bool
    {
        return trim(str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html)))) === '';
    }

    private function clockNow(): \DateTimeImmutable
    {
        return \Symfony\Component\Clock\now();
    }

    private function requireYear(EducationalCentre $centre): AcademicYear
    {
        return $this->tenantContext->getViewYear($centre) ?? throw $this->createNotFoundException();
    }

    private function requireTeacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
