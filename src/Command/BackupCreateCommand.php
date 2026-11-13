<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backup\BackupException;
use App\Service\Backup\DatabaseBackup;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:backup:create')]
class BackupCreateCommand extends Command
{
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%/var/backups')]
        private readonly string $defaultDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->translator->trans('backup_create.description', domain: 'command'))
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, $this->translator->trans('backup_create.option.output', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $target = $input->getOption('output');
        if (!is_string($target) || $target === '') {
            if (!is_dir($this->defaultDir) && !mkdir($this->defaultDir, 0700, true) && !is_dir($this->defaultDir)) {
                $io->error($this->translator->trans('backup_create.error.dir', ['%dir%' => $this->defaultDir], 'command'));

                return Command::FAILURE;
            }
            $target = $this->defaultDir . '/gestconv-copia-' . $this->clock->now()->format('Ymd-His') . '.zip';
        }

        try {
            $manifest = $this->backup->create($target);
        } catch (BackupException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($this->translator->trans('backup_create.success', [
            '%file%'   => $target,
            '%tables%' => count($manifest->tables),
            '%rows%'   => $manifest->totalRows(),
        ], 'command'));
        $io->note($this->translator->trans('backup_create.sensitive', domain: 'command'));

        return Command::SUCCESS;
    }
}
