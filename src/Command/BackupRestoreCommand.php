<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backup\BackupException;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\BackupManifest;
use App\Service\Backup\DatabaseRestore;
use App\Service\Backup\RestoreState;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Restaura una copia de seguridad, sustituyendo todos los datos. Mientras dura, la aplicación responde 503 (bloqueo en ficheros)
 * y al terminar bien se cierran todas las sesiones. La web (Administración › Copia de seguridad y versión) la lanza como
 * proceso aparte con `--lock-held`, `--previous-backup` y `--by`; a mano, `--unlock` quita un bloqueo atascado.
 */
#[AsCommand(name: 'app:backup:restore')]
class BackupRestoreCommand extends Command
{
    public function __construct(
        private readonly DatabaseRestore $restore,
        private readonly BackupLibrary $library,
        private readonly RestoreState $state,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->translator->trans('backup_restore.description', domain: 'command'))
            ->addArgument('file', InputArgument::OPTIONAL, $this->translator->trans('backup_restore.argument.file', domain: 'command'))
            ->addOption('force', null, InputOption::VALUE_NONE, $this->translator->trans('backup_restore.option.force', domain: 'command'))
            ->addOption('previous-backup', null, InputOption::VALUE_NONE, $this->translator->trans('backup_restore.option.previous_backup', domain: 'command'))
            ->addOption('unlock', null, InputOption::VALUE_NONE, $this->translator->trans('backup_restore.option.unlock', domain: 'command'))
            ->addOption('by', null, InputOption::VALUE_REQUIRED, $this->translator->trans('backup_restore.option.by', domain: 'command'))
            // Interna: la web ya tomó el bloqueo (para que el sitio se ponga en mantenimiento antes de que arranque este proceso).
            ->addOption('lock-held', null, InputOption::VALUE_NONE, $this->translator->trans('backup_restore.option.lock_held', domain: 'command'))
            ->setHelp($this->translator->trans('backup_restore.help', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        if ($input->getOption('unlock')) {
            $io->success($t($this->state->forceUnlock() ? 'backup_restore.unlocked' : 'backup_restore.not_locked'));

            return Command::SUCCESS;
        }

        $file = $input->getArgument('file');
        if (!is_string($file) || $file === '') {
            $io->error($t('backup_restore.error.no_file'));

            return Command::FAILURE;
        }

        $lockHeld = (bool) $input->getOption('lock-held');
        $by       = $input->getOption('by');
        $by       = is_string($by) && $by !== '' ? $by : 'consola';

        try {
            $manifest = $this->restore->readManifest($file);
            $problems = $this->restore->problems($manifest);
        } catch (BackupException $e) {
            return $this->fail($io, $e->getMessage(), $by, $file, $lockHeld);
        }

        $io->definitionList(
            [$t('backup_restore.info.app_version') => $manifest->appVersion],
            [$t('backup_restore.info.schema') => $manifest->schemaVersion ?? '—'],
            [$t('backup_restore.info.platform') => $manifest->platform],
            [$t('backup_restore.info.created') => $manifest->createdAt],
            [$t('backup_restore.info.rows') => (string) $manifest->totalRows()],
        );

        if ($problems !== []) {
            return $this->fail($io, implode("\n", $problems), $by, $file, $lockHeld);
        }

        if (!$lockHeld && !$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error($t('backup_restore.error.force_required'));

                return Command::FAILURE;
            }
            if (!$io->confirm($t('backup_restore.confirm'), false)) {
                $io->warning($t('backup_restore.cancelled'));

                return Command::FAILURE;
            }
        }

        // El bloqueo lo toma quien lanza la restauración; la consola, aquí. Se libera siempre, pase lo que pase.
        if (!$lockHeld && !$this->state->acquire($by, basename($file))) {
            $io->error($t('backup_restore.error.running'));

            return Command::FAILURE;
        }

        try {
            $this->state->attachPid((int) getmypid());

            return $this->perform($io, $manifest, $file, $by, (bool) $input->getOption('previous-backup'));
        } finally {
            $this->state->release();
        }
    }

    private function perform(SymfonyStyle $io, BackupManifest $manifest, string $file, string $by, bool $previousBackup): int
    {
        $t = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        try {
            if ($previousBackup) {
                $previous = $this->library->save('gestconv-antes-de-restaurar');
                $io->note($t('backup_restore.previous_saved', ['%name%' => $previous->name]));
            }

            $this->logger->warning('Restauración de copia de seguridad iniciada.', ['by' => $by, 'file' => basename($file), 'copy_version' => $manifest->appVersion]);

            $restored = $this->restore->restore($file, static function (string $table, int $rows) use ($io): void {
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('  %s: %d', $table, $rows));
                }
            });
        } catch (BackupException $e) {
            return $this->fail($io, $e->getMessage(), $by, $file, true);
        }

        $rows = array_sum($restored);
        $this->state->markRestored();
        $this->state->writeResult(['ok' => true, 'by' => $by, 'file' => basename($file), 'tables' => count($restored), 'rows' => $rows]);
        $this->logger->warning('Restauración de copia de seguridad terminada.', ['by' => $by, 'file' => basename($file), 'rows' => $rows]);

        $io->success($t('backup_restore.success', ['%tables%' => count($restored), '%rows%' => $rows]));

        return Command::SUCCESS;
    }

    /** Falla dejando constancia: en el registro del servidor y, para la pantalla de administración, en el resultado de la última restauración. */
    private function fail(SymfonyStyle $io, string $message, string $by, string $file, bool $recordResult): int
    {
        $io->error($message);
        $this->logger->error('Restauración de copia de seguridad fallida.', ['by' => $by, 'file' => basename($file), 'reason' => $message]);
        if ($recordResult) {
            $this->state->writeResult(['ok' => false, 'by' => $by, 'file' => basename($file), 'message' => $message]);
        }
        if ($recordResult) {
            // Con el bloqueo de la web, el hijo lo libera siempre aunque la validación inicial falle.
            $this->state->release();
        }

        return Command::FAILURE;
    }
}
