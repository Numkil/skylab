<?php


namespace Kunstmaan\Skylab\Command;


use Symfony\Component\Console\Input\InputArgument;

class FetchCommand extends AbstractCommand
{

    const TYPE_JAVA = "java";
    const TYPE_PHP = "php";

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this
            ->addDefaults()
            ->setName('fetch')
            ->setDescription('Fetches a project from a production server')
            ->addArgument('project', InputArgument::OPTIONAL, 'The name of the Skylab project')
            ->addArgument('host', InputArgument::OPTIONAL, 'The hostname of the server to fetch from')
            ->setHelp(<<<EOT
The <info>fetch</info> command fetches a Skylab project from a server and puts it in the right locations on your computer. It
will also drop the databases, so be very careful if you want to use this on a production server to do a migration.

<info>php skylab.phar fetch</info>                         # Will ask for a project and server to fetch it from
<info>php skylab.phar fetch testproject server1</info>     # Will fetch the testproject from server1

EOT
            );
    }

    /**
     * @throws \RuntimeException
     */
    protected function doExecute()
    {
        $projectname = $this->dialogProvider->askFor("Please enter the name of the project", 'project');
        $hostname = $this->dialogProvider->askFor("Please enter the hostname of the server", 'host');

        $this->dialogProvider->logStep("Checking preconditions");
        $this->dialogProvider->logTask("Checking the server");
        $exists = $this->remoteProjectExists($projectname, $hostname);
        if (!$exists) {
            throw new \RuntimeException("The project " . $projectname . " does not exist on " . $hostname);
        }
        $this->dialogProvider->logTask("Detecting the project type");
        $type = $this->detectProjectType($projectname, $hostname);

        $excludes = array(
            ".composer",
            "apachelogs/*",
            "resizedcache/*",
            "nobackup/*",
            "tmp/*",
            ".viminfo",
            ".ssh",
            ".bash_history",
            ".config",
            ".mysql_history",
            "data/current/app/logs/*",
            "data/current/app/cache/*"
        );

        if (!$this->fileSystemProvider->projectExists($projectname)) {
            $this->dialogProvider->logStep("Running the full rsync commands since " . $projectname . " is not on this computer");
            $full_excludes = $excludes;
            $full_excludes[] = "data/shared";
            $full_excludes[] = "data/releases";
            if ($type !== FetchCommand::TYPE_JAVA) {
                $full_excludes[] = "data/" . $projectname;
            }
            $this->fetchFolder(
                $this->app["config"]["projects"]["path"] . '/',
                $hostname,
                "/home/projects/" . $projectname,
                $full_excludes,
                true
            );
            if ($type !== FetchCommand::TYPE_JAVA) {
                $mv_command = "mv " . $this->fileSystemProvider->getProjectDirectory($projectname) . "/data/current " . $this->fileSystemProvider->getProjectDirectory($projectname) . "/data/" . $projectname;
                $this->processProvider->executeCommand($mv_command);
            }
        } else {
            $this->dialogProvider->logStep("Running the update rsync commands since " . $projectname . " already is on this computer");
            $update_excludes = $excludes;
            $update_excludes[] = "data/*";

            $this->fetchFolderIfExists(
                $this->fileSystemProvider->getProjectDirectory($projectname),
                $hostname,
                "/home/projects/" . $projectname,
                $update_excludes
            );

            $this->fetchFolderIfExists(
                $this->fileSystemProvider->getProjectDirectory($projectname) . "/data/" . $projectname . "/web/uploads/",
                $hostname,
                "/home/projects/" . $projectname . "/data/shared/web/uploads/*",
                $update_excludes
            );

            $this->fetchFolderIfExists(
                $this->fileSystemProvider->getProjectDirectory($projectname) . "/data/" . $projectname . "/sites/default/files/",
                $hostname,
                "/home/projects/" . $projectname . "/data/shared/sites/default/files/*",
                $update_excludes
            );
        }
        $this->dialogProvider->logStep("Dropping the databases");
        $this->dialogProvider->logTask("Dropping the MySQL database");
        $dbh = new \PDO('mysql:host=localhost;', "root", $this->app["config"]["mysql"]["password"]);
        $dbh->query("DROP DATABASE IF EXISTS " . $projectname);
        $this->dialogProvider->logTask("Dropping the PostgreSQL database");
        $dbh = new \PDO("pgsql:host=localhost;dbname=template1", $this->app["config"]["postgresql"]["user"], $this->app["config"]["postgresql"]["password"]);
        $dbh->query("DROP DATABASE IF EXISTS " . $projectname);
    }

    /**
     * Tries to detect if the remote project is a Java project.
     *
     * @param $projectname
     * @param $hostname
     * @return string
     */
    private function detectProjectType($projectname, $hostname)
    {
        $command = "ssh " . $hostname . " 'test -d /home/projects/" . $projectname . "/data/" . $projectname . "/src/be/smartlounge && echo found'";
        $this->dialogProvider->logCommand($command);
        $found = $this->processProvider->executeCommand($command, true);
        if ($found) {
            return FetchCommand::TYPE_JAVA;
        }
        return FetchCommand::TYPE_PHP;
    }

    /**
     * Tries to detect if the remote project exists
     *
     * @param $projectname
     * @param $hostname
     * @return bool
     */
    private function remoteProjectExists($projectname, $hostname)
    {
        $command = "ssh " . $hostname . " 'test -d /home/projects/" . $projectname . " && echo found'";
        $this->dialogProvider->logCommand($command);
        $found = $this->processProvider->executeCommand($command, true);
        if ($found) {
            return true;
        }
        return false;
    }

    /**
     * @param string $folder
     * @param string $hostname
     * @param string $remoteFolder
     * @param string[] $excludes
     * @param bool $links
     */
    private function fetchFolderIfExists($folder, $hostname, $remoteFolder, $excludes, $links = false)
    {
        if (file_exists($folder)) {
            $this->fetchFolder($folder, $hostname, $remoteFolder, $excludes, $links);
        }
    }

    /**
     * @param string $folder
     * @param string $hostname
     * @param string $remoteFolder
     * @param string[] $excludes
     * @param bool $links
     */
    private function fetchFolder($folder, $hostname, $remoteFolder, $excludes, $links = false)
    {
        $rsync_command = "rsync -r" . ($links ? "L" : "l") . "Dhz --info=progress2";
        foreach ($excludes as $exclude) {
            $rsync_command .= " --exclude=" . $exclude;
        }
        $rsync_command .= " " . $hostname . ":" . $remoteFolder;
        $rsync_command .= " " . $folder;
        /** @noinspection PhpUnusedParameterInspection */
        $this->processProvider->executeCommand($rsync_command, false, function ($type, $buffer) {
            echo $buffer;
        });
    }
}
