<?php

namespace DigipolisGent\Robo\Helpers;

use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAwareInterface;
use Robo\Contract\ConfigAwareInterface;
use Symfony\Component\Finder\Finder;

abstract class AbstractRoboFile extends \Robo\Tasks implements DigipolisPropertiesAwareInterface, ConfigAwareInterface
{
    use \DigipolisGent\Robo\Task\Package\Commands\loadCommands;
    use \DigipolisGent\Robo\Task\General\loadTasks;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \Robo\Common\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\Deploy\Commands\loadCommands;
    use \DigipolisGent\Robo\Task\Deploy\Traits\SshTrait;
    use \DigipolisGent\Robo\Task\Deploy\Traits\ScpTrait;
    use \Robo\Task\Base\loadTasks;

    /**
     * Stores the request time.
     *
     * @var int
     */
    protected $time;

    /**
     * File backup subdirs.
     *
     * @var type
     */
    protected $fileBackupSubDirs = [];

    /**
     * Create a RoboFileBase instance.
     */
    public function __construct()
    {
        $this->time = time();
    }

    /**
     * Build a site and push it to the servers.
     *
     * @param array $arguments
     *   Variable amount of arguments. The last argument is the path to the
     *   the private key file (ssh), the penultimate is the ssh user. All
     *   arguments before that are server IP's to deploy to.
     * @param array $opts
     *   The options for this task.
     *
     * @return \Robo\Contract\TaskInterface
     *   The deploy task.
     */
    protected function deployTask(
        array $arguments,
        $opts
    ) {
        $opts += ['force-install' => false];
        $privateKeyFile = array_pop($arguments);
        $user = array_pop($arguments);
        $servers = $arguments;
        $worker = is_null($opts['worker']) ? reset($servers) : $opts['worker'];
        $remote = $this->getRemoteSettings($servers, $user, $privateKeyFile, $opts['app']);
        $releaseDir = $remote['releasesdir'] . '/' . $remote['time'];
        $auth = new KeyFile($user, $privateKeyFile);
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $archive = $remote['time'] . '.tar.gz';
        $build = $this->buildTask($archive);

        $collection = $this->collectionBuilder();
        $collection->addTask($build);
        // Create a backup, and a rollback if a site is present.
        $status = $this->isSiteInstalled($worker, $auth, $remote);
        if ($status) {
            $collection->addTask($this->backupTask($worker, $auth, $remote));
            $collection->rollback(
                $this->restoreBackupTask(
                    $worker,
                    $auth,
                    $remote
                )
            );
            // Switch the current symlink to the previous release.
            $collection->rollback(
                $this->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->exec(
                        'vendor/bin/robo digipolis:switch-previous '
                        . $remote['releasesdir']
                        . ' ' . $remote['currentdir']
                    )
            );
        }
        foreach ($servers as $server) {
            $collection
                ->taskPushPackage($server, $auth)
                    ->destinationFolder($releaseDir)
                    ->package($archive);
            $preSymlink = $this->preSymlinkTask($server, $auth, $remote);
            if ($preSymlink) {
                $collection->addTask($preSymlink);
            }
            foreach ($remote['symlinks'] as $link) {
                $collection->exec('ln -s -T -f ' . str_replace(':', ' ', $link));
            }
        }
        $collection->addTask($this->initRemoteTask($worker, $auth, $remote, $opts, $opts['force-install']));
        if (isset($remote['opcache'])) {
            $clearOpcache = 'vendor/bin/robo digipolis:clear-op-cache ' . $remote['opcache']['env'];
            if ( isset($remote['opcache']['host'])) {
                $clearOpcache .= ' --host=' . $remote['opcache']['host'];
            }
            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->exec($clearOpcache);
        }
        foreach ($servers as $server) {
            $collection->completion($this->taskSsh($server, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(30)
                ->exec('vendor/bin/robo digipolis:clean-dir ' . $remote['releasesdir'])
                ->exec('vendor/bin/robo digipolis:clean-dir ' . $remote['backupsdir'])
            );
        }
        $clearCache = $this->clearCacheTask($worker, $auth, $remote);
        if ($clearCache) {
            // Clear cache.
            $collection->completion($clearCache);
        }
        return $collection;
    }

    /**
     * Check if a site is already installed
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool
     *   Whether or not the site is installed.
     */
    abstract protected function isSiteInstalled($worker, AbstractAuth $auth, $remote);

    /**
     * Clear cache of the site.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The clear cache task or false if no clear cache task exists.
     */
    protected function clearCacheTask($worker, $auth, $remote) {
      return false;
    }
    /**
     * Switch the current release symlink to the previous release.
     *
     * @param string $releasesDir
     *   Path to the folder containing all releases.
     * @param string $currentSymlink
     *   Path to the current release symlink.
     */
    public function digipolisSwitchPrevious($releasesDir, $currentSymlink)
    {
        $finder = new Finder();
        // Get all releases.
        $releases = iterator_to_array(
            $finder
                ->directories()
                ->in($releasesDir)
                ->sortByName()
                ->depth(0)
                ->getIterator()
        );
        // Last element is the current release.
        array_pop($releases);
        // Normalize the paths.
        $currentDir = realpath($currentSymlink);
        $releasesDir = realpath($releasesDir);
        // Get the right folder within the release dir to symlink.
        $relativeRootDir = substr($currentDir, strlen($releasesDir . '/'));
        $parts = explode('/', $relativeRootDir);
        array_shift($parts);
        $relativeWebDir = implode('/', $parts);
        $previous = end($releases)->getRealPath() . '/' . $relativeWebDir;

        return $this->taskExec('ln -s -T -f ' . $previous . ' ' . $currentSymlink)
            ->run();
    }

    /**
     * Build a site and package it.
     *
     * @param string $archivename
     *   Name of the archive to create.
     *
     * @return \Robo\Contract\TaskInterface
     *   The deploy task.
     */
    protected function buildTask($archivename = null)
    {
        $archive = is_null($archivename) ? $this->time . '.tar.gz' : $archivename;
        $collection = $this->collectionBuilder();
        $collection
            ->taskPackageProject($archive);
        return $collection;
    }

    /**
     * Tasks to execute before creating the symlinks.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The presymlink task, false if no pre symlink tasks need to run.
     */
    protected function preSymlinkTask($worker, AbstractAuth $auth, $remote)
    {
        return false;
    }

    /**
     * Install or update a remote site.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     * @param array $extra
     *   Extra parameters to pass to site install.
     * @param bool $force
     *   Whether or not to force the install even when the site is present.
     *
     * @return \Robo\Contract\TaskInterface
     *   The init remote task.
     */
    protected function initRemoteTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false) {
        $collection = $this->collectionBuilder();
        if (!$this->isSiteInstalled($worker, $auth, $remote) || $force) {
            $this->say($force ? 'Forcing site install.' : 'Site status failed.');
            $this->say('Triggering install script.');

            $collection->addTask($this->installTask($worker, $auth, $remote, $extra, $force));
            return $collection;
        }
        $collection->addTask($this->updateTask($worker, $auth, $remote, $extra));
        return $collection;
    }

    /**
     * Executes database updates of the site in the current folder.
     *
     * Executes database updates of the site in the current folder. Sets
     * the site in maintenance mode before the update and takes in out of
     * maintenance mode after.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The update task.
     */
    abstract protected function updateTask($worker, AbstractAuth $auth, $remote);

    /**
     * Install the site in the current folder.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     * @param bool $force
     *   Whether or not to force the install even when the site is present.
     *
     * @return \Robo\Contract\TaskInterface
     *   The install task.
     */
    abstract protected function installTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false);

    /**
     * Sync the database and files between two sites.
     *
     * @param string $sourceUser
     *   SSH user to connect to the source server.
     * @param string $sourceHost
     *   IP address of the source server.
     * @param string $sourceKeyFile
     *   Private key file to use to connect to the source server.
     * @param string $destinationUser
     *   SSH user to connect to the destination server.
     * @param string $destinationHost
     *   IP address of the destination server.
     * @param string $destinationKeyFile
     *   Private key file to use to connect to the destination server.
     * @param string $sourceApp
     *   The name of the source app we're syncing. Used to determine the
     *   directory to sync.
     * @param string $destinationApp
     *   The name of the destination app we're syncing. Used to determine the
     *   directory to sync to.
     *
     * @return \Robo\Contract\TaskInterface
     *   The sync task.
     */
    protected function syncTask(
        $sourceUser,
        $sourceHost,
        $sourceKeyFile,
        $destinationUser,
        $destinationHost,
        $destinationKeyFile,
        $sourceApp = 'default',
        $destinationApp = 'default'
    ) {
        $sourceRemote = $this->getRemoteSettings($sourceHost, $sourceUser, $sourceKeyFile, $sourceApp);
        $sourceAuth = new KeyFile($sourceUser, $sourceKeyFile);
        $destinationRemote = $this->getRemoteSettings($destinationHost, $destinationUser, $destinationKeyFile, $destinationApp);
        $destinationAuth = new KeyFile($destinationUser, $destinationKeyFile);

        $collection = $this->collectionBuilder();
        // Create a backup.
        $collection->addTask(
            $this->backupTask(
                $sourceHost,
                $sourceAuth,
                $sourceRemote
            )
        );
        // Download the backup.
        $collection->addTask(
            $this->downloadBackupTask(
                $sourceHost,
                $sourceAuth,
                $sourceRemote
            )
        );
        // Upload the backup.
        $collection->addTask(
            $this->uploadBackupTask(
                $destinationHost,
                $destinationAuth,
                $destinationRemote
            )
        );
        // Restore the backup.
        $collection->addTask(
            $this->restoreBackupTask(
                $destinationHost,
                $destinationAuth,
                $destinationRemote
            )
        );
        return $collection;
    }

    /**
     * Create a backup of files (storage folder) and database.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The backup task.
     */
    protected function backupTask($worker, AbstractAuth $auth, $remote)
    {
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $currentProjectRoot = $remote['currentdir'] . '/..';

        $dbBackupFile = $this->backupFileName('.sql');
        $dbBackup = 'vendor/bin/robo digipolis:database-backup '
            . '--destination=' . $backupDir . '/' . $dbBackupFile;

        $filesBackupFile = $this->backupFileName('.tar.gz');
        $filesBackup = 'tar -pczhf ' . $backupDir . '/'  . $filesBackupFile
            . ' -C ' . $remote['filesdir'] . ' ' . implode(' ', $this->fileBackupSubDirs);

        $collection = $this->collectionBuilder();
        $collection
            ->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->exec('mkdir -p ' . $backupDir)
                ->exec($dbBackup)
                ->exec($filesBackup);
        return $collection;
    }

    /**
     * Restore a backup of files (storage folder) and database.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The restore backup task.
     */
    protected function restoreBackupTask($worker, AbstractAuth $auth, $remote) {

        $currentProjectRoot = $remote['currentdir'] . '/..';
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];

        $filesBackupFile =  $this->backupFileName('.tar.gz', $remote['time']);
        $dbBackupFile =  $this->backupFileName('.sql.gz', $remote['time']);

        $dbRestore = 'vendor/bin/robo digipolis:database-restore '
              . '--source=' . $backupDir . '/' . $dbBackupFile;
        $collection = $this->collectionBuilder();

        // Restore the files backup.
        $preRestoreBackup = $this->preRestoreBackupTask($worker, $auth, $remote);
        if ($preRestoreBackup) {
            $collection->addTask($preRestoreBackup);
        }
        $collection
            ->taskSsh($worker, $auth)
                ->remoteDirectory($remote['filesdir'], true)
                ->exec('tar -xkzf ' . $backupDir . '/' . $filesBackupFile);

        // Restore the db backup.
        $collection
            ->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(60)
                ->exec($dbRestore);
        return $collection;
    }

    /**
     * Pre restore backup task.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The pre restore backup task, false if no pre restore backup tasks need
     *   to run.
     */
    protected function preRestoreBackupTask($worker, AbstractAuth $auth, $remote)
    {
        return false;
    }

    /**
     * Download a backup of files (storage folder) and database.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The download backup task.
     */
    protected function downloadBackupTask($worker, AbstractAuth $auth, $remote) {
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $dbBackupFile = $this->backupFileName('.sql.gz', $remote['time']);
        $filesBackupFile = $this->backupFileName('.tar.gz', $remote['time']);

        $collection = $this->collectionBuilder();
        $collection
            ->taskScp($worker, $auth)
                ->get($backupDir . '/' . $dbBackupFile, $dbBackupFile)
                ->get($backupDir . '/' . $filesBackupFile, $filesBackupFile);
        return $collection;
    }

    /**
     * Upload a backup of files (storage folder) and database to a server.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The upload backup task.
     */
    protected function uploadBackupTask($worker, AbstractAuth $auth, $remote) {
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $dbBackupFile = $this->backupFileName('.sql.gz', $remote['time']);
        $filesBackupFile = $this->backupFileName('.tar.gz', $remote['time']);

        $collection = $this->collectionBuilder();
        $collection
            ->taskSsh($worker, $auth)
                ->exec('mkdir -p ' . $backupDir)
            ->taskScp($host, $auth)
                ->put($backupDir . '/' . $dbBackupFile, $dbBackupFile)
                ->put($backupDir . '/' . $filesBackupFile, $filesBackupFile);
        return $collection;
    }

    /**
     * Helper functions to replace tokens in an array.
     *
     * @param string|array $input
     *   The array or string containing the tokens to replace.
     * @param array $replacements
     *   The token replacements.
     *
     * @return string|array
     *   The input with the tokens replaced with their values.
     */
    protected function tokenReplace($input, $replacements)
    {
        if (is_string($input)) {
            return strtr($input, $replacements);
        }
        if (is_scalar($input) || empty($input)) {
            return $input;
        }
        foreach ($input as &$i) {
            $i = $this->tokenReplace($i, $replacements);
        }
        return $input;
    }

    /**
     * Generate a backup filename based on the given time.
     *
     * @param string $extension
     *   The extension to append to the filename. Must include leading dot.
     * @param int|null $timestamp
     *   The timestamp to generate the backup name from. Defaults to the request
     *   time.
     *
     * @return string
     *   The generated filename.
     */
    protected function backupFileName($extension, $timestamp = null)
    {
        if (is_null($timestamp)) {
            $timestamp = $this->time;
        }
        return $timestamp . '_' . date('Y_m_d_H_i_s', $timestamp) . $extension;
    }

    /**
     * Get the settings from the 'remote' config key, with the tokens replaced.
     *
     * @param string $host
     *   The IP address of the server to get the settings for.
     * @param string $user
     *   The SSH user used to connect to the server.
     * @param string $keyFile
     *   The path to the private key file used to connect to the server.
     * @param string $app
     *   The name of the app these settings apply to.
     * @param string|null $timestamp
     *   The timestamp to use. Defaults to the request time.
     *
     * @return array
     *   The settings for this server and app.
     */
    protected function getRemoteSettings($host, $user, $keyFile, $app, $timestamp = null)
    {
        $this->readProperties();
        $defaults = [
            'user' => $user,
            'private-key' => $keyFile,
            'app' => $app,
            'time' => is_null($timestamp) ? $this->time : $timestamp,
        ];

        // Set up destination config.
        $replacements = array(
            '[user]' => $user,
            '[private-key]' => $keyFile,
            '[app]' => $app,
            '[time]' => is_null($timestamp) ? $this->time : $timestamp,
        );
        if (is_string($host)) {
            $replacements['[server]'] = $host;
            $defaults['server'] = $host;
        }
        if (is_array($host)) {
            foreach ($host as $key => $server) {
                $replacements['[server-' . $key . ']'] = $server;
                $defaults['server-' . $key] = $server;
            }
        }
        return $this->tokenReplace($this->getConfig()->get('remote'), $replacements) + $defaults;
    }
}