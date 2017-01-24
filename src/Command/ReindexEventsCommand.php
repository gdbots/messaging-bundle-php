<?php
declare(strict_types = 1);

namespace Gdbots\Bundle\PbjxBundle\Command;

use Gdbots\Common\Util\NumberUtils;
use Gdbots\Pbj\WellKnown\Microtime;
use Gdbots\Schemas\Pbjx\Mixin\Event\Event;
use Gdbots\Schemas\Pbjx\StreamId;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReindexEventsCommand extends ContainerAwareCommand
{
    use PbjxAwareCommandTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('pbjx:reindex-events')
            ->setDescription('Pipes events from the EventStore for a given stream id and reindexes them.')
            ->setHelp(<<<EOF
The <info>%command.name%</info> command will pipe events from the pbjx EventStore for a given stream id 
and reindex them into the EventSearch service.

<info>php %command.full_name% --dry-run --tenant-id=client1 'stream-id'</info>

EOF
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Pipes events and renders output but will NOT actually reindex.'
            )
            ->addOption(
                'skip-errors',
                null,
                InputOption::VALUE_NONE,
                'Skip any batches that fail to reindex.'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of events to reindex at a time.',
                100
            )
            ->addOption(
                'batch-delay',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of milliseconds (1000 = 1 second) to delay between batches.',
                1000
            )
            ->addOption(
                'since',
                null,
                InputOption::VALUE_REQUIRED,
                'Reindex events where occurred_at is greater than this time ' .
                '(unix timestamp or 16 digit microtime as int).'
            )
            ->addOption(
                'until',
                null,
                InputOption::VALUE_REQUIRED,
                'Reindex events where occurred_at is less than this time ' .
                '(unix timestamp or 16 digit microtime as int).'
            )
            ->addOption(
                'tenant-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Tenant Id to use for this operation.'
            )
            ->addOption(
                'context',
                null,
                InputOption::VALUE_REQUIRED,
                'Context to provide to the EventStore (json).'
            )
            ->addArgument(
                'stream-id',
                InputArgument::REQUIRED,
                'The stream to reindex messages from.  See Gdbots\Schemas\Pbjx\StreamId for details.'
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $streamId = StreamId::fromString($input->getArgument('stream-id'));
        $dryRun = $input->getOption('dry-run');
        $skipErrors = $input->getOption('skip-errors');
        $batchSize = NumberUtils::bound($input->getOption('batch-size'), 1, 1000);
        $batchDelay = NumberUtils::bound($input->getOption('batch-delay'), 100, 600000);
        $since = $input->getOption('since');
        $until = $input->getOption('until');
        $context = json_decode($input->getOption('context') ?: '{}', true);
        $context['tenant_id'] = $input->getOption('tenant-id');
        $context['skip_errors'] = $skipErrors;
        $context['reindexing'] = true;

        if (!empty($since)) {
            $since = Microtime::fromString(str_pad($since, 16, '0'));
        }

        if (!empty($until)) {
            $until = Microtime::fromString(str_pad($until, 16, '0'));
        }

        $io = new SymfonyStyle($input, $output);
        $io->title(sprintf('Reindexing events from stream "%s"', $streamId));
        if (!$this->readyForPbjxTraffic($io)) {
            return;
        }

        $this->createConsoleRequest();
        $pbjx = $this->getPbjx();
        $batch = 1;
        $i = 0;
        $reindexed = 0;
        $queue = [];
        $io->comment(sprintf('Processing batch %d from stream "%s".', $batch, $streamId));
        $io->comment(sprintf('context: %s', json_encode($context)));
        $io->newLine();

        $receiver = function (Event $event) use (
            $streamId,
            $output,
            $io,
            $context,
            $dryRun,
            $skipErrors,
            $batchSize,
            $batchDelay,
            &$batch,
            &$reindexed,
            &$i,
            &$queue
        ) {
            ++$i;
            $output->writeln(
                sprintf(
                    '<info>%d.</info> <comment>occurred_at:</comment>%s, <comment>curie:</comment>%s, ' .
                    '<comment>event_id:</comment>%s',
                    $i,
                    $event->get('occurred_at'),
                    $event::schema()->getCurie()->toString(),
                    $event->get('event_id')
                )
            );
            $queue[] = $event->freeze();

            if (0 === $i % $batchSize) {
                $this->reindex($queue, $reindexed, $io, $context, $batch, $dryRun, $skipErrors);
                ++$batch;

                if ($batchDelay > 0) {
                    $io->newLine();
                    $io->note(sprintf('Pausing for %d milliseconds.', $batchDelay));
                    usleep($batchDelay * 1000);
                }

                $io->comment(sprintf('Processing batch %d from stream "%s".', $batch, $streamId));
                $io->newLine();
            }
        };

        $pbjx->getEventStore()->pipeEvents($streamId, $receiver, $since, $until, $context);
        $this->reindex($queue, $reindexed, $io, $context, $batch, $dryRun, $skipErrors);
        $io->newLine();
        $io->success(sprintf('Reindexed %s events from stream "%s".', number_format($reindexed), $streamId));
    }

    /**
     * @param array        $queue
     * @param int          $reindexed
     * @param SymfonyStyle $io
     * @param array        $context
     * @param int          $batch
     * @param bool         $dryRun
     * @param bool         $skipErrors
     *
     * @throws \Exception
     */
    protected function reindex(array &$queue, int &$reindexed, SymfonyStyle $io, array $context, int $batch, bool $dryRun = false, bool $skipErrors = false): void
    {
        if ($dryRun) {
            $io->note(sprintf('DRY RUN - Would reindex event batch %d here.', $batch));
        } else {
            try {
                $this->getPbjx()->getEventSearch()->indexEvents($queue, $context);
            } catch (\Exception $e) {
                $io->error($e->getMessage());
                $io->note(sprintf('Failed to index batch %d.', $batch));
                $io->newLine(2);

                if (!$skipErrors) {
                    throw $e;
                }
            }
        }

        $reindexed += count($queue);
        $queue = [];
    }
}
