<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backup\BackupException;
use App\Service\Backup\DatabaseRestore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Restaura una copia de seguridad. Solo desde la línea de órdenes: sustituye todos los datos, así que no se ofrece en la web. */
#[AsCommand(name: 'app:backup:restore')]
class BackupRestoreCommand extends Command
{
    public function __construct(
        private readonly DatabaseRestore $restore,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->translator->trans('backup_restore.description', domain: 'command'))
            ->addArgument('file', InputArgument::REQUIRED, $this->translator->trans('backup_restore.argument.file', domain: 'command'))
            ->addOption('force', null, InputOption::VALUE_NONE, $this->translator->trans('backup_restore.option.force', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        $file = is_string($file) ? $file : '';
        $t    = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        try {
            $manifest = $this->restore->readManifest($file);
            $problems = $this->restore->problems($manifest);
        } catch (BackupException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            [$t('backup_restore.info.app_version') => $manifest->appVersion],
            [$t('backup_restore.info.schema') => $manifest->schemaVersion ?? '—'],
            [$t('backup_restore.info.platform') => $manifest->platform],
            [$t('backup_restore.info.created') => $manifest->createdAt],
            [$t('backup_restore.info.rows') => (string) $manifest->totalRows()],
        );

        if ($problems !== []) {
            $io->error($problems);

            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error($t('backup_restore.error.force_required'));

                return Command::FAILURE;
            }
            if (!$io->confirm($t('backup_restore.confirm'), false)) {
                $io->warning($t('backup_restore.cancelled'));

                return Command::FAILURE;
            }
        }

        try {
            $restored = $this->restore->restore($file, static function (string $table, int $rows) use ($io): void {
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('  %s: %d', $table, $rows));
                }
            });
        } catch (BackupException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($t('backup_restore.success', ['%tables%' => count($restored), '%rows%' => array_sum($restored)]));

        return Command::SUCCESS;
    }
}
