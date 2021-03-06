<?php

declare(strict_types=1);

namespace Rector\Standalone;

use Psr\Container\ContainerInterface;
use Rector\Application\ErrorAndDiffCollector;
use Rector\Application\RectorApplication;
use Rector\Autoloading\AdditionalAutoloader;
use Rector\Configuration\Configuration;
use Rector\Configuration\Option;
use Rector\Console\Command\ProcessCommand;
use Rector\Console\Output\ConsoleOutputFormatter;
use Rector\DependencyInjection\RectorContainerFactory;
use Rector\Exception\FileSystem\FileNotFoundException;
use Rector\Extension\FinishingExtensionRunner;
use Rector\Extension\ReportingExtensionRunner;
use Rector\FileSystem\FilesFinder;
use Rector\Guard\RectorGuard;
use Rector\PhpParser\NodeTraverser\RectorNodeTraverser;
use Rector\Stubs\StubLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symplify\PackageBuilder\FileSystem\SmartFileInfo;

/**
 * This class is needed over process/cli run to get console output in sane way;
 * without it, it's not possible to get inside output closed stream.
 */
final class RectorStandaloneRunner
{
    /**
     * @var RectorContainerFactory
     */
    private $rectorContainerFactory;

    /**
     * @var SymfonyStyle
     */
    private $nativeSymfonyStyle;

    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(RectorContainerFactory $rectorContainerFactory, SymfonyStyle $symfonyStyle)
    {
        $this->rectorContainerFactory = $rectorContainerFactory;
        $this->nativeSymfonyStyle = $symfonyStyle;
    }

    /**
     * @param string[] $source
     */
    public function processSourceWithSet(
        array $source,
        string $set,
        bool $isDryRun,
        bool $isQuietMode = false
    ): ErrorAndDiffCollector {
        $source = $this->absolutizeSource($source);

        $this->container = $this->rectorContainerFactory->createFromSet($set);

        // silent Symfony style
        if ($isQuietMode) {
            $this->nativeSymfonyStyle->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }

        $this->prepare($source, $isDryRun);

        $phpFileInfos = $this->findFilesInSource($source);
        $this->runRectorOnFileInfos($phpFileInfos);

        if ($isQuietMode === false) {
            $this->reportErrors();
        }

        $this->finish();

        return $this->container->get(ErrorAndDiffCollector::class);
    }

    /**
     * Mostly copied from: https://github.com/rectorphp/rector/blob/master/src/Console/Command/ProcessCommand.php.
     * @param string[] $source
     */
    private function prepare(array $source, bool $isDryRun): void
    {
        ini_set('memory_limit', '4096M');

        /** @var RectorNodeTraverser $rectorNodeTraverser */
        $rectorNodeTraverser = $this->container->get(RectorNodeTraverser::class);
        $this->prepareConfiguration($rectorNodeTraverser, $isDryRun);

        /** @var RectorGuard $rectorGuard */
        $rectorGuard = $this->container->get(RectorGuard::class);
        $rectorGuard->ensureSomeRectorsAreRegistered();

        // setup verbosity from the current run
        /** @var SymfonyStyle $symfonyStyle */
        $symfonyStyle = $this->container->get(SymfonyStyle::class);
        $symfonyStyle->setVerbosity($this->nativeSymfonyStyle->getVerbosity());

        /** @var AdditionalAutoloader $additionalAutoloader */
        $additionalAutoloader = $this->container->get(AdditionalAutoloader::class);
        $additionalAutoloader->autoloadWithInputAndSource(new ArrayInput([]), $source);

        /** @var StubLoader $stubLoader */
        $stubLoader = $this->container->get(StubLoader::class);
        $stubLoader->loadStubs();
    }

    private function reportErrors(): void
    {
        /** @var ErrorAndDiffCollector $errorAndDiffCollector */
        $errorAndDiffCollector = $this->container->get(ErrorAndDiffCollector::class);

        /** @var ConsoleOutputFormatter $consoleOutputFormatter */
        $consoleOutputFormatter = $this->container->get(ConsoleOutputFormatter::class);
        $consoleOutputFormatter->report($errorAndDiffCollector);
    }

    /**
     * @param string[] $source
     * @return string[]
     */
    private function absolutizeSource(array $source): array
    {
        foreach ($source as $key => $singleSource) {
            /** @var string $singleSource */
            if (! file_exists($singleSource)) {
                throw new FileNotFoundException($singleSource);
            }

            /** @var string $realpath */
            $realpath = realpath($singleSource);
            $source[$key] = $realpath;
        }

        return $source;
    }

    private function finish(): void
    {
        /** @var FinishingExtensionRunner $finishingExtensionRunner */
        $finishingExtensionRunner = $this->container->get(FinishingExtensionRunner::class);
        $finishingExtensionRunner->run();

        /** @var ReportingExtensionRunner $reportingExtensionRunner */
        $reportingExtensionRunner = $this->container->get(ReportingExtensionRunner::class);
        $reportingExtensionRunner->run();
    }

    private function prepareConfiguration(RectorNodeTraverser $rectorNodeTraverser, bool $isDryRun): void
    {
        /** @var Configuration $configuration */
        $configuration = $this->container->get(Configuration::class);

        $configuration->setAreAnyPhpRectorsLoaded((bool) $rectorNodeTraverser->getPhpRectorCount());

        // definition mimics @see ProcessCommand definition
        /** @var ProcessCommand $processCommand */
        $processCommand = $this->container->get(ProcessCommand::class);
        $definition = clone $processCommand->getDefinition();

        // reset arguments to prevent "source is missing"
        $definition->setArguments([]);

        $configuration->resolveFromInput(new ArrayInput([
            '--' . Option::OPTION_DRY_RUN => $isDryRun,
            '--' . Option::OPTION_OUTPUT_FORMAT => 'console',
        ], $definition));
    }

    /**
     * @param string[] $source
     * @return SmartFileInfo[]
     */
    private function findFilesInSource(array $source): array
    {
        /** @var FilesFinder $filesFinder */
        $filesFinder = $this->container->get(FilesFinder::class);

        return $filesFinder->findInDirectoriesAndFiles($source, ['php']);
    }

    /**
     * @param SmartFileInfo[] $phpFileInfos
     */
    private function runRectorOnFileInfos(array $phpFileInfos): void
    {
        /** @var RectorApplication $rectorApplication */
        $rectorApplication = $this->container->get(RectorApplication::class);
        $rectorApplication->runOnFileInfos($phpFileInfos);
    }
}
