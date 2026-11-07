<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ActivityLogRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly EducationalCentreRepository $centres,
        private readonly ActivityLogRepository $activityLogs,
    ) {}

    #[Route('/admin', name: 'app_admin')]
    public function index(): Response
    {
        return $this->render('admin/index.html.twig', [
            'stats' => [
                'teachers_total'  => $this->teachers->countAll(),
                'teachers_active' => $this->teachers->countActive(),
                'teachers_admin'  => $this->teachers->countAdmins(),
                'non_teaching_total' => $this->teachers->countNonTeaching(),
                'centres_total'   => $this->centres->countAll(),
                'logs_today'      => $this->activityLogs->countToday(),
            ],
        ]);
    }

    /** Direcciones antiguas de la gestión de usuarios (se llamaba «docentes»): se redirigen a las nuevas. */
    #[Route('/admin/docentes/{rest}', name: 'app_admin_teachers_legacy', requirements: ['rest' => '.*'], defaults: ['rest' => ''], methods: ['GET'], priority: -10)]
    public function legacyUsers(string $rest): Response
    {
        return $this->redirect('/admin/usuarios' . ($rest !== '' ? '/' . $rest : ''), Response::HTTP_MOVED_PERMANENTLY);
    }
}
