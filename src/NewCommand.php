<?php

namespace Voyager\Installer\Console;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Console\Question\Question;
use Dotenv\Dotenv;


class NewCommand extends Command
{
    private $dotenv;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Laravel application.')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('database', null, InputOption::VALUE_NONE, 'Will prompt for more database fields, such as connection, host, and port')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        

        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        $directory = ($input->getArgument('name')) ? getcwd().'/'.$input->getArgument('name') : getcwd();

        if (! $input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        $this->printASCII($output);
        $output->writeln('<info>Ahoy Matey! Welcome to the Voyager Installer!</info>');

        $version = $this->getVersion($input);

        $this->download($zipFile = $this->makeFilename(), $version)
             ->extract($zipFile, $directory)
             ->prepareWritableDirectories($directory, $output)
             ->cleanUp($zipFile);

        $composer = $this->findComposer();

        $helper = $this->getHelper('question');
        $output->writeln('<info>After you\'ve created a database for this application, enter in the credentials below:</info>');

        if($input->getOption('database')){

            $db_connection_q = new Question('DB_CONNECTION=', 'mysql');
            $db_connection = $helper->ask($input, $output, $db_connection_q);
            $db_host_q = new Question('DB_HOST=', '127.0.0.1');
            $db_host = $helper->ask($input, $output, $db_host_q);
            $db_port_q = new Question('DB_PORT=', '3306');
            $db_port = $helper->ask($input, $output, $db_port_q);
        }

        $db_database_q = new Question('DB_DATABASE=', '');
        $db_database = $helper->ask($input, $output, $db_database_q);

        $db_username_q = new Question('DB_USERNAME=', '');
        $db_username = $helper->ask($input, $output, $db_username_q);

        $db_password_q = new Question('DB_PASSWORD=', '');
        $db_password = $helper->ask($input, $output, $db_password_q);


        $output->writeln('<info>Finally, we just need your application URL to finish the install:</info>');

        $app_url_q = new Question('APP_URL=', 'app_url');
        $app_url = $helper->ask($input, $output, $app_url_q);


        $output->writeln('<info>Setting Sail! Crafting your new application...</info>');
        
        $commands = [
            $composer.' require tcg/voyager',
            $composer.' install --no-scripts',
            $composer.' run-script post-root-package-install',
            $composer.' run-script post-create-project-cmd',
            $composer.' run-script post-autoload-dump',
        ];

        if ($input->getOption('dev')) {
            unset($commands[2]);

            $commands[] = $composer.' run-script post-autoload-dump';
        }

        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                return $value.' --no-ansi';
            }, $commands);
        }

        $process = new Process(implode(' && ', $commands), $directory, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<info>Updating Environment Variables</info>');
        $this->dotenv = new Dotenv($directory);
        $this->dotenv->load();
        $this->changeEnvironmentVariable($directory, 'APP_URL', $app_url);

        if($input->getOption('database')){
            $this->changeEnvironmentVariable($directory, 'DB_CONNECTION', $db_connection);
            $this->changeEnvironmentVariable($directory, 'DB_HOST', $db_host);
            $this->changeEnvironmentVariable($directory, 'DB_PORT', $db_port);
        }

        $this->changeEnvironmentVariable($directory, 'DB_DATABASE', $db_database);
        $this->changeEnvironmentVariable($directory, 'DB_USERNAME', $db_username);
        $this->changeEnvironmentVariable($directory, 'DB_PASSWORD', $db_password);

        // Load new modified dot env
        $this->dotenv = new Dotenv($directory);
        $this->dotenv->load();

        $output->writeln('<info>Installing Voyager assets and migrations...</info>');

        $process = new Process('cd '.$directory.' && php artisan cache:clear && php artisan config:clear && php artisan voyager:install --with-dummy && open ' . trim($app_url, '/') . '/admin');

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<comment>Application ready! Build something amazing.</comment>');
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    protected function printASCII($output){
        $output->writeln('                                          ');
        $output->writeln('  _    __                                 ');
        $output->writeln(' | |  / /___  __  ______ _____ ____  _____');
        $output->writeln(' | | / / __ \/ / / / __ `/ __ `/ _ \/ ___/');
        $output->writeln(' | |/ / /_/ / /_/ / /_/ / /_/ /  __/ /    ');    
        $output->writeln(' |___/\____/\__, /\__,_/\__, /\___/_/     ');    
        $output->writeln('           /____/      /____/             ');    
        $output->writeln('                                          ');
    }

    /**
     * Change Environment variables
     * @param  string $key
     * @param  string $value
     * @return void
     */
    protected function changeEnvironmentVariable($directory, $key,$value)
    {
        $path = $directory . '/.env';
        $old = '';
        if(!empty(getenv($key)))
        {
            $old = getenv($key);
        }

        if (file_exists($path)) {
            file_put_contents($path, str_replace(
                "$key=".$old, "$key=".$value, file_get_contents($path)
            ));
            putenv($key. '=' . $value);
        }
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd().'/laravel_'.md5(time().uniqid()).'.zip';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $zipFile
     * @param  string  $version
     * @return $this
     */
    protected function download($zipFile, $version = 'master')
    {
        switch ($version) {
            case 'develop':
                $filename = 'latest-develop.zip';
                break;
            case 'master':
                $filename = 'latest.zip';
                break;
        }

        $response = (new Client)->get('http://cabinet.laravel.com/'.$filename);

        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Extract the Zip file into the given directory.
     *
     * @param  string  $zipFile
     * @param  string  $directory
     * @return $this
     */
    protected function extract($zipFile, $directory)
    {
        $archive = new ZipArchive;

        $archive->open($zipFile);

        $archive->extractTo($directory);

        $archive->close();

        return $this;
    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string  $zipFile
     * @return $this
     */
    protected function cleanUp($zipFile)
    {
        @chmod($zipFile, 0777);

        @unlink($zipFile);

        return $this;
    }

    /**
     * Make sure the storage and bootstrap cache directories are writable.
     *
     * @param  string  $appDirectory
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return $this
     */
    protected function prepareWritableDirectories($appDirectory, OutputInterface $output)
    {
        $filesystem = new Filesystem;

        try {
            $filesystem->chmod($appDirectory.DIRECTORY_SEPARATOR."bootstrap/cache", 0755, 0000, true);
            $filesystem->chmod($appDirectory.DIRECTORY_SEPARATOR."storage", 0755, 0000, true);
        } catch (IOExceptionInterface $e) {
            $output->writeln('<comment>You should verify that the "storage" and "bootstrap/cache" directories are writable.</comment>');
        }

        return $this;
    }

    /**
     * Get the version that should be downloaded.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('dev')) {
            return 'develop';
        }

        return 'master';
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        if (file_exists(getcwd().'/composer.phar')) {
            return '"'.PHP_BINARY.'" composer.phar';
        }

        return 'composer';
    }
}
