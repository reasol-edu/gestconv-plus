<?php

declare(strict_types=1);

namespace App\Service\Backup;

/** Una copia de seguridad que no se puede crear o restaurar; el mensaje es legible por quien administra el servidor. */
final class BackupException extends \RuntimeException {}
