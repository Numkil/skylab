<?php
namespace Kunstmaan\Skylab\Command;

use Kunstmaan\Skylab\Skeleton\BaseSkeleton;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SetPermissionsCommand
 */
class SetPermissionsCommand extends AbstractCommand
{

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this
            ->addDefaults()
            ->setName('permissions')
            ->setDescription('Set the permissions of a Skylab project')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the project')
            ->setHelp(<<<EOT
The <info>permissions</info> command will fix the permissions of a project.

<info>php skylab.phar permissions testproject</info>

EOT
            );
    }

    /**
     * @throws \RuntimeException
     */
    protected function doExecute()
    {
        $projectname = $this->input->getArgument('name');

        if (!$this->fileSystemProvider->projectExists($projectname)) {
            throw new RuntimeException("The $projectname project does not exist.");
        }

        $this->dialogProvider->logStep($this->output, OutputInterface::VERBOSITY_NORMAL, "Setting permissions on project $projectname");

        /** @var BaseSkeleton $baseSkeleton */
        $baseSkeleton = $this->skeletonProvider->findSkeleton("base", $this->output);
        $project = $this->projectConfigProvider->loadProjectConfig($projectname, $this->output);
        $baseSkeleton->setPermissions($this->getContainer(), $project, $this->output, true);
    }
}
