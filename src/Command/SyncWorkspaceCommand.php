<?php declare(strict_types=1);

namespace Devshot\Connector\Command;

use Devshot\Connector\Service\WorkspaceSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'devshot:workspace:sync',
    description: 'Erstellt ein anonymisiertes Shopware Backup und sendet Projektdateien ohne Media an den DevShot Workspace.'
)]
class SyncWorkspaceCommand extends Command
{
    public function __construct(private readonly WorkspaceSyncService $workspaceSyncService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('endpoint', null, InputOption::VALUE_REQUIRED, 'DevShot Workspace Upload Endpoint')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Bearer Token für den Workspace Upload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $endpoint = (string) ($input->getOption('endpoint') ?: getenv('DEVSHOT_ENDPOINT'));
        $token = (string) ($input->getOption('token') ?: getenv('DEVSHOT_TOKEN'));

        if ($endpoint === '' || $token === '') {
            $io->error('DEVSHOT_ENDPOINT und DEVSHOT_TOKEN müssen gesetzt sein.');

            return Command::INVALID;
        }

        $io->section('DevShot Shopware Workspace Sync');
        $io->writeln('Erstelle anonymisiertes Datenbank-Backup.');
        $io->writeln('Packe Projektdateien ohne Media, Cache, Vendor, Secrets und lokale Laufzeitdaten.');

        $result = $this->workspaceSyncService->sync($endpoint, $token);

        $io->success(sprintf('Workspace Sync "%s" wurde übertragen.', $result['syncId']));

        if (is_array($result['workspaceResponse']) && isset($result['workspaceResponse']['testserverUrl'])) {
            $io->writeln(sprintf('Lokaler Testserver: %s', $result['workspaceResponse']['testserverUrl']));
        }

        return Command::SUCCESS;
    }
}
