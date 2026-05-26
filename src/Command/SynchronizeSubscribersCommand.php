<?php

namespace Welp\MailchimpBundle\Command;

use DrewM\MailChimp\MailChimp;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Welp\MailchimpBundle\Provider\ListProviderInterface;
use Welp\MailchimpBundle\Subscriber\ListSynchronizer;

class SynchronizeSubscribersCommand extends Command
{
    /**
     * List Synchronizer.
     *
     * @var ListSynchronizer
     */
    private ListSynchronizer $listSynchronizer;

    /**
     * The configured list provider.
     *
     * @var ListProviderInterface
     */
    private ListProviderInterface $listProvider;

    /**
     * Mailchimp API class.
     *
     * @var MailChimp
     */
    private MailChimp $mailchimp;

    public function __construct(ListSynchronizer $listSynchronizer, ListProviderInterface $listProvider, MailChimp $mailchimp)
    {
        $this->listSynchronizer = $listSynchronizer;
        $this->listProvider = $listProvider;
        $this->mailchimp = $mailchimp;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Synchronizing subscribers in MailChimp')
            ->setName('welp:mailchimp:synchronize-subscribers')
            ->addOption(
                'follow-sync',
                null,
                InputOption::VALUE_NONE,
                'If you want to follow batches execution'
            )
            // @TODO add params : listId, providerServiceKey
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('<info>%s</info>', $this->getDescription()));

        $lists = $this->listProvider->getLists();

        foreach ($lists as $list) {
            $output->writeln(sprintf('Synchronize list %s', $list->getListId()));
            $batchesResult = $this->listSynchronizer->synchronize($list);

            if ($input->getOption('follow-sync')) {
                $maxIterations = 300; // max ~10 minuten (300 x sleep(2))
                $iterations = 0;
                while (!$this->batchesFinished($batchesResult) && $iterations < $maxIterations) {
                    $batchesResult = $this->refreshBatchesResult($batchesResult);

                    foreach ($batchesResult as $key => $batch) {
                        $output->writeln($this->displayBatchInfo($batch));
                    }

                    sleep(2);
                    ++$iterations;
                }

                if ($iterations >= $maxIterations) {
                    $output->writeln('<error>Timeout: batches niet afgerond binnen 10 minuten.</error>');
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Refresh all batch from MailChimp API.
     *
     * @param array $batchesResult
     *
     * @return array
     */
    private function refreshBatchesResult(array $batchesResult): array
    {
        $refreshedBatchsResults = [];

        foreach ($batchesResult as $batch) {
            $batchId = $batch['id'] ?? null;
            if ($batchId === null) {
                // vorige iteratie gaf al een error: behandel als klaar
                $refreshedBatchsResults[] = ['status' => 'finished', 'id' => '?'];
                continue;
            }
            $result = $this->mailchimp->get('batches/'.$batchId);
            // false of een API-fout (bijv. 404 verlopen batch) → behandel als klaar
            if (!is_array($result) || !isset($result['id'])) {
                $refreshedBatchsResults[] = ['status' => 'finished', 'id' => $batchId];
                continue;
            }
            $refreshedBatchsResults[] = $result;
        }

        return $refreshedBatchsResults;
    }

    /**
     * Test if all batches are finished.
     *
     * @param array $batchesResult
     *
     * @return bool
     */
    private function batchesFinished(array $batchesResult): bool
    {
        foreach ($batchesResult as $batch) {
            if ('finished' !== ($batch['status'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pretty display of batch info.
     *
     * @param array $batch
     *
     * @return string
     */
    private function displayBatchInfo(array $batch): string
    {

		$batchId = $batch['id'] ?? '?';
		$batchStatus = $batch['status'] ?? '?';
	    $batchFinished = $batch['finished_operations'] ?? '?';
	    $batchTotal = $batch['total_operations'] ?? '?';
	    $batchErrored = $batch['errored_operations'] ?? '?';
		$batchResponseUrl = $batch['response_body_url'] ?? '?';

        if ('finished' === ($batch['status'] ?? null)) {
            return sprintf('batch %s is finished, operations %d/%d with %d errors. http responses: %s', $batchId, $batchFinished, $batchTotal, $batchErrored, $batchResponseUrl);
        }

        return sprintf('batch %s, current status %s, operations %d/%d with %d errors', $batchId, $batchStatus, $batchFinished, $batchTotal, $batchErrored);
    }
}
