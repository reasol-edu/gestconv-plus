<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ForcePasswordChangeControllerTest extends ControllerTestCase
{
    public function testGetRendersFormForFlaggedLocalTeacher(): void
    {
        $teacher = $this->makeTeacherWithPassword('change.get', 'old-password-123', forcePasswordChange: true);
        $this->loginAs($teacher);

        $this->client->request('GET', '/cambio-contrasena-obligatorio');

        self::assertResponseIsSuccessful();
    }

    public function testGetRedirectsToDashboardForUnflaggedTeacher(): void
    {
        $teacher = $this->makeTeacherWithPassword('change.unflagged', 'old-password-123', forcePasswordChange: false);
        $this->loginAs($teacher);

        $this->client->request('GET', '/cambio-contrasena-obligatorio');

        self::assertResponseRedirects();
        self::assertNotSame('/cambio-contrasena-obligatorio', $this->client->getResponse()->headers->get('Location'));
    }

    public function testGetRedirectsToDashboardForFlaggedExternalTeacher(): void
    {
        $teacher = $this->makeTeacherWithPassword('change.external', 'old-password-123', forcePasswordChange: true, external: true);
        $this->loginAs($teacher);

        $this->client->request('GET', '/cambio-contrasena-obligatorio');

        self::assertResponseRedirects();
        self::assertNotSame('/cambio-contrasena-obligatorio', $this->client->getResponse()->headers->get('Location'));
    }

    public function testPostWithValidDataChangesPasswordAndClearsFlag(): void
    {
        $teacher   = $this->makeTeacherWithPassword('change.success', 'old-password-123', forcePasswordChange: true);
        $teacherId = $teacher->getId();
        $this->loginAs($teacher);

        $csrfToken = $this->getCsrfTokenFromPage('/cambio-contrasena-obligatorio');

        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            'current_password'     => 'old-password-123',
            'new_password'         => 'new-password-456',
            'new_password_confirm' => 'new-password-456',
            '_csrf_token'          => $csrfToken,
        ]);

        self::assertResponseRedirects('/');
        $this->em->clear();
        $fresh = $this->em->find(Teacher::class, $teacherId);
        self::assertNotNull($fresh);
        self::assertFalse($fresh->isForcePasswordChange());

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($fresh, 'new-password-456'));
    }

    public function testPostWithWrongCurrentPasswordShowsError(): void
    {
        $teacher = $this->makeTeacherWithPassword('change.wrongcurrent', 'old-password-123', forcePasswordChange: true);
        $this->loginAs($teacher);

        $csrfToken = $this->getCsrfTokenFromPage('/cambio-contrasena-obligatorio');

        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            'current_password'     => 'wrong-password',
            'new_password'         => 'new-password-456',
            'new_password_confirm' => 'new-password-456',
            '_csrf_token'          => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testPostWithTooShortNewPasswordShowsError(): void
    {
        $teacher = $this->makeTeacherWithPassword('change.tooshort', 'old-password-123', forcePasswordChange: true);
        $this->loginAs($teacher);

        $csrfToken = $this->getCsrfTokenFromPage('/cambio-contrasena-obligatorio');

        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            'current_password'     => 'old-password-123',
            'new_password'         => 'short',
            'new_password_confirm' => 'short',
            '_csrf_token'          => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testPostWithMismatchedConfirmationShowsError(): void
    {
        $teacher = $this->makeTeacherWithPassword('change.mismatch', 'old-password-123', forcePasswordChange: true);
        $this->loginAs($teacher);

        $csrfToken = $this->getCsrfTokenFromPage('/cambio-contrasena-obligatorio');

        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            'current_password'     => 'old-password-123',
            'new_password'         => 'new-password-456',
            'new_password_confirm' => 'different-password-789',
            '_csrf_token'          => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testPostWithInvalidCsrfTokenShowsGeneralError(): void
    {
        $teacher = $this->makeTeacherWithPassword('change.csrf', 'old-password-123', forcePasswordChange: true);
        $this->loginAs($teacher);

        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            'current_password'     => 'old-password-123',
            'new_password'         => 'new-password-456',
            'new_password_confirm' => 'new-password-456',
            '_csrf_token'          => 'invalid-token',
        ]);

        self::assertResponseIsSuccessful();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeTeacherWithPassword(
        string $username,
        string $plainPassword,
        bool $forcePasswordChange = false,
        bool $external = false,
    ): Teacher {
        $teacher = (new Teacher(new PersonName('Test', 'User')))
            ->setUsername($username)
            ->setExternal($external)
            ->setForcePasswordChange($forcePasswordChange);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $teacher->setPassword($hasher->hashPassword($teacher, $plainPassword));

        $this->persist($teacher);

        return $teacher;
    }

    private function getCsrfTokenFromPage(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $input   = $crawler->filter('input[name="_csrf_token"]');

        return $input->count() > 0 ? ($input->attr('value') ?? '') : '';
    }
}
