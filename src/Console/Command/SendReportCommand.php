<?php

namespace W2e\Ticpan\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use W2e\Ticpan\Model\Agent;

class SendReportCommand extends Command
{
    /** @var Agent */
    private $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ticpan:report:send')
            ->setDescription('Envía un reporte de salud a Ticpan inmediatamente (sin esperar al cron)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Enviando reporte a Ticpan...</info>');
        $this->agent->run();
        $output->writeln('<info>Listo. Revisa var/log/system.log para ver el resultado.</info>');
        return 0;
    }
}
