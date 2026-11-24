<?php

declare(strict_types=1);

namespace Sattleme\Payment\Console\Command;

use Sattleme\Payment\Model\Privacy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento sattleme:payment:privacy export|erase --email=<address> | --order=<number>: a
 * customer's personal data in the module's records (Privacy).
 */
class PrivacyCommand extends Command
{
    /**
     * @param Privacy $privacy The personal data.
     * @param string|null $name The command's name.
     */
    public function __construct(private readonly Privacy $privacy, ?string $name = null)
    {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('sattleme:payment:privacy')
            ->setDescription('Exports or erases a customer\'s personal data in the bitcoin payments of their orders')
            ->addArgument('action', InputArgument::REQUIRED, 'export (JSON on the output) or erase')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'The customer\'s email address: all their orders')
            ->addOption('order', null, InputOption::VALUE_REQUIRED, 'An order\'s number');
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $email = $input->getOption('email');
        $number = $input->getOption('order');
        if (!in_array($action, ['export', 'erase'], true) || (!is_string($email) && !is_string($number))) {
            $output->writeln(
                '<error>Usage: sattleme:payment:privacy export|erase --email=<address> | --order=<number></error>'
            );

            return 2;
        }
        $orders = $this->privacy->orders(is_string($email) ? $email : null, is_string($number) ? $number : null);
        if ($action === 'export') {
            $output->writeln(
                json_encode(
                    $this->privacy->export($orders),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                OutputInterface::OUTPUT_RAW
            );

            return 0;
        }
        $erased = $this->privacy->erase($orders);
        $output->writeln(sprintf(
            '%d order(s): the deposit addresses and transactions of %d final payment(s) were erased; '
            . 'those still being checked are erased once final.',
            count($orders),
            $erased
        ));

        return 0;
    }
}
