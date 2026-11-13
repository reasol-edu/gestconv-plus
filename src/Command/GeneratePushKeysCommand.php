<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:push:generate-keys', description: 'Genera un par de claves VAPID para los avisos push')]
final class GeneratePushKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        /** @var array{publicKey: string, privateKey: string} $keys */
        $keys = VAPID::createVapidKeys();

        $io->writeln([
            'Añade estas líneas al fichero de entorno (.env.local, o las variables del servicio) y reinicia la aplicación.',
            'La clave privada es un secreto: no la compartas ni la subas al repositorio.',
            '',
            'VAPID_PUBLIC_KEY=' . $keys['publicKey'],
            'VAPID_PRIVATE_KEY=' . $keys['privateKey'],
            'VAPID_SUBJECT=mailto:tu-correo@tu-centro.example',
        ]);

        return Command::SUCCESS;
    }
}
