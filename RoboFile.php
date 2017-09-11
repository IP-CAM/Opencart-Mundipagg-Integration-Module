<?php
use Symfony\Component\Console\Helper\ProgressBar;
use Behat\Gherkin\Exception\Exception;

require_once 'vendor/autoload.php';

if (file_exists('.env')) {
    $dotenv = new Dotenv\Dotenv(__DIR__);
    $dotenv->load();
}

class RoboFile extends \Robo\Tasks
{
    use \Robo\Common\TaskIO;

    public function __construct()
    {
        $this->opencart_config = [
            'db_hostname' => getenv('DB_HOST'),
            'db_username' => getenv('DB_USER'),
            'db_password' => getenv('DB_PASSWORD'),
            'db_database' => getenv('DB_NAME'),
            'db_driver' => getenv('DB_DRIVER'),
            'username' => getenv('OC_ADMIN_USER'),
            'password' => getenv('OC_ADMIN_PASS'),
            'email' => getenv('OC_ADMIN_MAIL'),
            'http_server' => getenv('HTTP_SERVER')
        ];
    }

    public function opencartSetup()
    {
        $this->downloadAndExtract();
        $this->filesToRunTests();
        $this->dropIfExistsAndCreateDatabase();
        $this->setupOpenCart();
        $this->installModuleByModman();
    }

    private function downloadAndExtract()
    {
        if (!getenv('REGENERATE_OPENCART_DIR')) {
            return;
        }
        $this->taskDeleteDir(getenv('OC_ROOT'))->run();
        $filename = getenv('OPENCART_VERSION') . '-OpenCart.zip';
        $this->downloadOpenCart($filename);
        $this->extractOpenCart($filename);
    }

    private function downloadOpenCart(string $filename)
    {
        $url = 'https://github.com/opencart/opencart/releases/download/'
            . getenv('OPENCART_VERSION')
            . '/'
            . $filename;
        $headers = get_headers($url, 1);
        $filesize = $headers['Content-Length'];
        if (!file_exists($filename) || filesize($filename) != $filesize) {
            $remote = fopen($url, 'r');
            if ($remote === false) {
                throw new Exception('Failed to access ' . $url);
            }
            $local = fopen($filename, 'w');
            if ($local === false) {
                throw new Exception('Failed to write ' . $filename);
            }
            $progress = new ProgressBar($this->output(), $filesize / 1024);
            $progress->start();
            $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
            $read_bytes = 0;
            while (!feof($remote)) {
                $buffer = fread($remote, 1024);
                fwrite($local, $buffer);
                $read_bytes += 1024;
                $progress->advance();
            }
            $progress->finish();
            $this->output()->writeln('');
            fclose($remote);
            fclose($local);
        }
    }

    private function extractOpenCart(string $filename)
    {
        $zip = new ZipArchive;
        $res = $zip->open('./' . $filename);
        if ($res !== true) {
            throw new Exception('Failed to open ./' . $filename);
        }
        $tmpPath = $this->collectionBuilder()->tmpDir();
        $zip->extractTo($tmpPath);
        $this->taskFilesystemStack()->mkdir(getenv('OC_ROOT'));
        $this->taskMirrorDir([
            $tmpPath . DIRECTORY_SEPARATOR . 'upload' => getenv('OC_ROOT')
        ])->run();
        $this->taskDeleteDir($tmpPath)->run();
    }

    private function filesToRunTests()
    {
        $this->taskFileSystemStack()
            ->copy('system/config/test-config.php', getenv('OC_ROOT') . 'system/config/test-config.php')
            ->copy('vendor/beyondit/opencart-test-suite/src/upload/system/library/session/test.php', getenv('OC_ROOT') . 'system/library/session/test.php')
            ->copy('vendor/beyondit/opencart-test-suite/src/upload/admin/controller/startup/test_startup.php', getenv('OC_ROOT') . 'admin/controller/startup/test_startup.php')
            ->run();
    }

    private function dropIfExistsAndCreateDatabase()
    {
        if (!getenv('REGENERATE_OPENCART_DIR')) {
            return;
        }
        try {
            $conn = new PDO(
                'mysql:host=' . $this->opencart_config['db_hostname'],
                $this->opencart_config['db_username'],
                $this->opencart_config['db_password']
            );
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->exec('DROP DATABASE IF EXISTS `' . $this->opencart_config['db_database'] . '`');
            $conn->exec('CREATE DATABASE `' . $this->opencart_config['db_database'] . '`');
        } catch (PDOException $e) {
            throw new Exception('Database error: ' . $e->getMessage());
        }
    }

    private function setupOpenCart()
    {
        if (!getenv('REGENERATE_OPENCART_DIR')) {
            return;
        }
        $this->taskFileSystemStack()
            ->chmod(getenv('OC_ROOT'), 0777, 0000, true)->run();

        $this->taskReplaceInFile(getenv('OC_ROOT') . 'install/cli_install.php')
            ->from("error_reporting(E_ALL);\r\n")
            ->to(
                "error_reporting(E_ALL);\r\n" .
                "define('DIR_STORAGE', '" . __DIR__ . "/system/storage/');"
            )
            ->run();

        $this->taskConcat([
            getenv('OC_ROOT') . 'tmp_setup.php',
            getenv('OC_ROOT') . 'install/cli_install.php'
        ])
        ->to(getenv('OC_ROOT') . 'install/cli_install.php')
        ->run();

        $install = $this->taskExec('php')
            ->arg(getenv('OC_ROOT') . 'install/cli_install.php')
            ->arg('install');
        foreach ($this->opencart_config as $option => $value) {
            $install->option($option, $value);
        }
        $install->rawArg('> /dev/null');
        $install->run();

        $this->taskMirrorDir([
            getenv('OC_ROOT') . 'system' . DIRECTORY_SEPARATOR . 'storage' => __DIR__ . DIRECTORY_SEPARATOR . 'storage'
        ])->run();

        $this->taskDeleteDir(getenv('OC_ROOT') . 'system'.DIRECTORY_SEPARATOR.'storage')->run();

        $this->taskReplaceInFile(getenv('OC_ROOT') . DIRECTORY_SEPARATOR . 'config.php')
            ->from("define('DIR_STORAGE', DIR_SYSTEM . 'storage/');")
            ->to("define('DIR_STORAGE', '" . __DIR__ . DIRECTORY_SEPARATOR."storage/');")
            ->run();

        $this->taskReplaceInFile(getenv('OC_ROOT') . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'config.php')
            ->from("define('DIR_STORAGE', DIR_SYSTEM . 'storage/');")
            ->to("define('DIR_STORAGE', '" . __DIR__ . DIRECTORY_SEPARATOR . "storage/');")
            ->run();

        $this->taskReplaceInFile(getenv('OC_ROOT') . 'install/cli_install.php')
            ->from(
                "error_reporting(E_ALL);\r\n" .
                "define('DIR_STORAGE', '" . __DIR__ . "/system/storage/');"
            )
            ->to("error_reporting(E_ALL);\r\n")
            ->run();
    }

    public function installModuleByModman()
    {
        if (!getenv('REGENERATE_OPENCART_DIR')) {
            return;
        }
        if (!is_dir('.modman')) {
            $this->taskExec('vendor/bin/modman init ' . getenv('OC_ROOT'))->run();
            $this->taskExec('ln -sf "$(pwd)" .modman/')->run();
        }
        $this->taskExec('vendor/bin/modman deploy-all --no-clean > /dev/null')->run();
    }
}
