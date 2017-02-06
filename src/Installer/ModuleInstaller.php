<?php

namespace Nova\Composer\Installer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;

use RuntimeException;


class ModuleInstaller extends LibraryInstaller
{
    /**
     * A flag to check usage - once
     *
     * @var bool
     */
    protected static $checkUsage = true;


    /**
     * Check usage upon construction
     *
     * @param IOInterface $io composer object
     * @param Composer    $composer composer object
     * @param string      $type what are we loading
     * @param Filesystem  $filesystem composer object
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null)
    {
        parent::__construct($io, $composer, $type, $filesystem);

        $this->checkUsage($composer);
    }

    /**
     * Check that the root composer.json file use the post-autoload-dump hook
     *
     * If not, warn the user they need to update their application's composer file.
     * Do nothing if the main project is not a project (if it's a module in development).
     *
     * @param Composer $composer object
     * @return void
     */
    public function checkUsage(Composer $composer)
    {
        if (static::$checkUsage === false) {
            return;
        }

        static::$checkUsage = false;

        $package = $composer->getPackage();

        if (! $package || ($package->getType() !== 'project')) {
            return;
        }

        $scripts = $composer->getPackage()->getScripts();

        $postAutoloadDump = 'Nova\Composer\Installer\ModuleInstaller::postAutoloadDump';

        if (! isset($scripts['post-autoload-dump']) || ! in_array($postAutoloadDump, $scripts['post-autoload-dump'])) {
            $this->warnUser(
                'Action required!',
                'Please update your application composer.json file to add the post-autoload-dump hook.'
            );
        }
    }

    /**
     * Warn the developer of action they need to take
     *
     * @param string $title Warning title
     * @param string $text warning text
     *
     * @return void
     */
    public function warnUser($title, $text)
    {
        $wrap = function ($text, $width = 75) {
            return '<error>     ' .str_pad($text, $width) .'</error>';
        };

        $messages = array(
            '',
            '',
            $wrap(''),
            $wrap($title),
            $wrap(''),
        );

        $lines = explode("\n", wordwrap($text, 68));

        foreach ($lines as $line) {
            $messages[] = $wrap($line);
        }

        $messages = array_merge($messages, array($wrap(''), '', ''));

        $this->io->write($messages);
    }

    /**
     * Called whenever composer (re)generates the autoloader
     *
     * Recreates Nova's module path map, based on composer information and available app-modules.
     *
     * @param Event $event the composer event object
     * @return void
     */
    public static function postAutoloadDump(Event $event)
    {
        $composer = $event->getComposer();

        $config = $composer->getConfig();

        $vendorDir = realpath($config->get('vendor-dir'));

        $packages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();

        $modulesDir = dirname($vendorDir) .DIRECTORY_SEPARATOR .'modules';

        $modules = static::determineModules($packages, $modulesDir, $vendorDir);

        $configFile = static::getConfigFile($vendorDir);

        static::writeConfigFile($configFile, $modules);
    }

    /**
     * Find all modules available
     *
     * Add all composer packages of type nova-module, and all modules located
     * in the modules directory to a module-name indexed array of paths
     *
     * @param array $packages an array of \Composer\Package\PackageInterface objects
     * @param string $modulesDir the path to the modules dir
     * @param string $vendorDir the path to the vendor dir
     * @return array module-name indexed paths to modules
     */
    public static function determineModules($packages, $modulesDir = 'modules', $vendorDir = 'vendor')
    {
        $modules = array();

        foreach ($packages as $package) {
            if ($package->getType() !== 'nova-module') {
                continue;
            }

            $namespace = static::primaryNamespace($package);

            $path = $vendorDir . DIRECTORY_SEPARATOR . $package->getPrettyName();

            $version = $package->getVersion();

            $modules[$namespace] = array('version' => $version, 'path' => $path, 'location' => 'vendor');
        }

        if (is_dir($modulesDir)) {
            $dir = new \DirectoryIterator($modulesDir);

            foreach ($dir as $info) {
                if (! $info->isDir() || $info->isDot()) {
                    continue;
                }

                $name = $info->getFilename();

                $path = $modulesDir . DIRECTORY_SEPARATOR . $name;

                // Determine the local Package version.
                $filePath = $path . DIRECTORY_SEPARATOR .'module.json';

                echo var_export($filePath, true) ."\n\n";

                if (is_readable($filePath)) {
                    $properties = json_decode(file_get_contents($filePath), true);

                    $version = $properties['version'];
                } else {
                    $version = '0.0.0';
                }

                $modules[$name] = array('version' => $version, 'path' => $path, 'location' => 'local');
            }
        }

        ksort($modules);

        return $modules;
    }

    /**
     * Rewrite the config file with a complete list of modules
     *
     * @param string $configFile the path to the config file
     * @param array $modules of modules
     * @return void
     */
    public static function writeConfigFile($configFile, $modules)
    {
        $root = dirname(dirname($configFile));

        $data = array();

        foreach ($modules as $name => $properties) {
            $modulePath = $properties['path'];
            $version    = $properties['version'];
            $location   = $properties['location'];

            //
            $modulePath = str_replace(
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
                $modulePath
            );

            // Normalize to *nix paths.
            $modulePath = str_replace('\\', '/', $modulePath);

            $modulePath .= '/';

            // Namespaced modules should use /
            $name = str_replace('\\', '/', $name);

            $data[] = sprintf(
"        '%s' => array(
             'path'     => '%s',
             'version'  => '%s',
             'location' => '%s',
         )", $name, $modulePath, $version, $location);
        }

        $data = implode(",\n", $data);

        if (! empty($data)) {
            $contents = <<<PHP
<?php

\$baseDir = dirname(dirname(__FILE__));

return array(
    'modules' => array(
$data
    )
);

PHP;
        } else {
            $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'modules' => array()
);
PHP;
        }

        $root = str_replace(
            DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR,
            $root
        );

        // Normalize to *nix paths.
        $root = str_replace('\\', '/', $root);

        $contents = str_replace('\'' . $root, '$baseDir . \'', $contents);

        file_put_contents($configFile, $contents);
    }

    /**
     * Path to the module config file
     *
     * @param string $vendorDir path to composer-vendor dir
     * @return string absolute file path
     */
    protected static function getConfigFile($vendorDir)
    {
        return $vendorDir . DIRECTORY_SEPARATOR . 'nova-modules.php';
    }

    /**
     * Get the primary namespace for a module package.
     *
     * @param \Composer\Package\PackageInterface $package composer object
     * @return string The package's primary namespace.
     * @throws \RuntimeException When the package's primary namespace cannot be determined.
     */
    public static function primaryNamespace($package)
    {
        $namespace = null;

        $autoLoad = $package->getAutoload();

        foreach ($autoLoad as $type => $pathMap) {
            if ($type !== 'psr-4') {
                continue;
            }

            $count = count($pathMap);

            if ($count === 1) {
                $namespace = key($pathMap);

                break;
            }

            $matches = preg_grep('#^(\./)?src/?$#', $pathMap);

            if ($matches) {
                $namespace = key($matches);

                break;
            }

            foreach (array('', '.') as $path) {
                $key = array_search($path, $pathMap, true);

                if ($key !== false) {
                    $namespace = $key;
                }
            }

            break;
        }

        if (is_null($namespace)) {
            throw new RuntimeException(
                sprintf(
                    "Unable to get primary namespace for package %s." .
                    "\nEnsure you have added proper 'autoload' section to your Module's config" .
                    " as stated in README on https://github.com/nova-framework/module-installer",
                    $package->getName()
                )
            );
        }

        return trim($namespace, '\\');
    }

    /**
     * Decides if the installer supports the given type.
     *
     * This installer only supports package of type 'nova-module'.
     *
     * @return bool
     */
    public function supports($packageType)
    {
        return ('nova-module' === $packageType);
    }

    /**
     * Installs specific module.
     *
     * After the module is installed, app's `nova-modules.php` config file is updated with
     * module namespace to path mapping.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $package Package instance.
     * @deprecated superceeded by the post-autoload-dump hook
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $path = $this->getInstallPath($package);

        $namespace = static::primaryNamespace($package);

        $version = $package->getVersion();

        $this->updateConfig($namespace, $path, $version);
    }

    /**
     * Updates specific module.
     *
     * After the module is installed, app's `nova-modules.php` config file is updated with
     * module namespace to path mapping.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $initial Already installed package version.
     * @param \Composer\Package\PackageInterface $target Updated version.
     * @deprecated superceeded by the post-autoload-dump hook
     *
     * @throws \InvalidArgumentException if $initial package is not installed
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);

        $namespace = static::primaryNamespace($initial);

        $this->updateConfig($namespace, null);

        $path = $this->getInstallPath($target);

        $namespace = static::primaryNamespace($target);

        $version = $target->getVersion();

        $this->updateConfig($namespace, $path, $version);
    }

    /**
     * Uninstalls specific package.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $package Package instance.
     * @deprecated superceeded by the post-autoload-dump hook
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);

        $path = $this->getInstallPath($package);

        $namespace = static::primaryNamespace($package);

        $this->updateConfig($namespace, null);
    }

    /**
     * Update the module path for a given package.
     *
     * @param string $name The module name being installed.
     * @param string $path The path, the module is being installed into.
     * @param string $version The module version being installed.
     */
    public function updateConfig($name, $path, $version = null)
    {
        $name = str_replace('\\', '/', $name);

        $configFile = static::getConfigFile($this->vendorDir);

        $this->ensureConfigFile($configFile);

        $return = include $configFile;

        if (is_array($return) && empty($config)) {
            $config = $return;
        }

        if (! isset($config)) {
            $this->io->write(
                'ERROR - `vendor/nova-modules.php` file is invalid. Module path configuration not updated.'
            );

            return;
        }

        if (! isset($config['modules'])) {
            $config['modules'] = array();
        }

        if (is_null($path)) {
            unset($config['modules'][$name]);
        } else {
            $path = str_replace(
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
                $path
            );

            // Normalize to *nix paths.
            $path = str_replace('\\', '/', $path);

            $path .= '/';

            $config['modules'][$name] = array('version' => $version, 'path' => $path, 'location' => 'vendor');
        }

        $this->writeConfig($configFile, $config);
    }

    /**
     * Ensure that the vendor/nova-modules.php file exists.
     *
     * @param string $path the config file path.
     * @return void
     */
    protected function ensureConfigFile($path)
    {
        if (file_exists($path)) {
            if ($this->io->isVerbose()) {
                $this->io->write('vendor/nova-modules.php exists.');
            }

            return;
        }

        $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'modules' => array()
);
PHP;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path));
        }

        file_put_contents($path, $contents);

        if ($this->io->isVerbose()) {
            $this->io->write('Created vendor/nova-modules.php');
        }
    }

    /**
     * Dump the generate configuration out to a file.
     *
     * @param string $path The path to write.
     * @param array $config The config data to write.
     * @return void
     */
    protected function writeConfig($path, $config)
    {
        $root = dirname($this->vendorDir);

        $data = '';

        foreach ($config['modules'] as $name => $properties) {
            $modulePath = $properties['path'];
            $version    = $properties['version'];
            $location   = $properties['location'];

            //
            $data .= sprintf(
"        '%s' => array(
             'path'     => '%s',
             'version'  => '%s',
             'location' => '%s',
         ),", $name, $modulePath, $version, $location);
        }

        if (! empty($data)) {
            $contents = <<<PHP
<?php

\$baseDir = dirname(dirname(__FILE__));

return array(
    'modules' => array(
$data
    )
);

PHP;
        } else {
            $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'modules' => array()
);
PHP;
        }

        $root = str_replace('\\', '/', $root);

        $contents = str_replace('\'' . $root, '$baseDir . \'', $contents);

        file_put_contents($path, $contents);
    }
}
