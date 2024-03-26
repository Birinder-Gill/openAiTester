<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Str;


class InitializeAi extends Command
{

    protected $directories = [
        "/node_modules",
        "/public/build",
        "/public/hot",
        "/public/storage",
        "/storage/*.key",
        "/vendor",
        ".env",
        ".env.backup",
        ".env.production",
        ".phpunit.result.cache",
        "Homestead.json",
        "Homestead.yaml",
        "auth.json",
        "npm-debug.log",
        "yarn-error.log",
        "/.fleet",
        "/.idea",
        "/.vscode",
        "/vendor/",
        "node_modules/",
        "npm-debug.log",
        "yarn-error.log",
        "# Laravel 4 specific",
        "bootstrap/compiled.php",
        "app/storage/",
        "# Laravel 5 & Lumen specific",
        "public/storage",
        "public/hot",
        "# Laravel 5 & Lumen specific with changed public path",
        "public_html/storage",
        "public_html/hot",
        "public/vendor/*",
        "storage/*.key",
        ".env",
        "Homestead.yaml",
        "Homestead.json",
        "/.vagrant",
        ".phpunit.result.cache",
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'init:ai';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make this project ready to use openai assistants.';

    // Environment variables
    protected $laravelVersion;
    protected $composerVersion;
    protected $osInfo;

    public function __construct()
    {
        parent::__construct();

        // Assuming these values are set elsewhere in the constructor or before handle() is called
        $this->laravelVersion = app()->version();
        $this->composerVersion = exec('composer --version');
        $this->osInfo = php_uname('s');
    }

    public function handle()
    {
        $this->call("storage:link");
        return Command::SUCCESS;

        if (config('app.env' !== 'local')) {
            $composerCmd = strpos($this->composerVersion, '1.') === false ? 'composer' : 'composer2';
            $this->runProcess([$composerCmd, 'update']);
            $this->error("This command should only be run on local enviorment.");
            return Command::FAILURE;
        }
        $this->line("Laravel version = {$this->laravelVersion}");
        $this->line("Composer version = {$this->composerVersion}");
        $this->line("Operation system = {$this->osInfo}");


        // Check for Composer version and set the command accordingly
        $composerCmd = 'composer'; //strpos($this->composerVersion, '1.') === false ? 'composer' : 'composer2';

        // 1. Require openai-php/client if not already installed
        $this->requireComposerPackage('openai-php/client', $composerCmd);

        // 2. Check Laravel version and GuzzleHTTP requirement
        if (version_compare($this->laravelVersion, '11.0', '<') && !$this->isPackageInstalled('guzzlehttp/guzzle')) {
            $this->requireComposerPackage('guzzlehttp/guzzle', $composerCmd);
        }

        // 3. Require opcodesio/log-viewer if not already installed
        $this->requireComposerPackage('opcodesio/log-viewer', $composerCmd);

        // 4. Modify .gitignore and handle untracked files
        $this->modifyGitignoreAndCleanUntracked($this->directories);

        // Create models with migrations
        $this->call('make:model', ['name' => 'OpenAiMessageTrack', '--migration' => true]);
        $this->call('make:model', ['name' => 'OpenAiLock', '--migration' => true]);
        $this->call('make:model', ['name' => 'OpenAiThread', '--migration' => true]);

        // Manually add fillable properties to each model
        $this->addFillableProperty('OpenAiMessageTrack', ['threadId', 'message']);
        $this->addFillableProperty('OpenAiLock', ['threadId']);
        $this->addFillableProperty('OpenAiThread', ['threadId', 'from']);

        $this->addColumnsToMigration('OpenAiMessageTrack', [
            '$table->string(\'threadId\');',
            '$table->text(\'message\');'
        ]);
        $this->addColumnsToMigration('OpenAiLock', [
            '$table->string(\'threadId\');',
        ]);
        $this->addColumnsToMigration('OpenAiThread', [
            '$table->string(\'threadId\');',
            '$table->string(\'from\');'
        ]);

        $this->call("migrate");
        $this->call("storage:link");

        // 6. Create a service and register it
        $this->createServiceAndRegister('OpenAiAnalysisService');

        // 7. Add variables to config/app.php
        $this->addVariablesToConfig();
        return Command::SUCCESS;
    }


    private function addFillableProperty($modelName, $fields)
    {
        $modelPath = app_path("Models/{$modelName}.php");
        if (file_exists($modelPath)) {
            $content = file_get_contents($modelPath);
            $fillableArray = "protected \$fillable = ['" . implode("', '", $fields) . "'];\n";
            $content = preg_replace('/{/', "{\n    $fillableArray", $content, 1);
            file_put_contents($modelPath, $content);
            $this->info("$modelName model updated with fillable fields.");
        } else {
            $this->error("$modelName model does not exist.");
        }
    }

    private function isPackageInstalled($packageName)
    {
        $composerLockPath = base_path('composer.lock');
        if (!file_exists($composerLockPath)) {
            $this->error('composer.lock file not found. Please run "composer install" to generate one.');
            return false;
        }

        $lockFileContents = json_decode(file_get_contents($composerLockPath), true);
        foreach ($lockFileContents['packages'] as $package) {
            if ($package['name'] === $packageName) {
                return true;
            }
        }

        // Also check packages in the 'packages-dev' section for completeness
        foreach ($lockFileContents['packages-dev'] as $package) {
            if ($package['name'] === $packageName) {
                return true;
            }
        }

        return false;
    }


    private function requireComposerPackage($packageName, $composerCmd)
    {
        if (!$this->isPackageInstalled($packageName)) {
            $this->info("Installing package: $packageName...");
            try {
                $this->runProcess([$composerCmd, 'require', $packageName]);
                $this->info("Package $packageName installed successfully.");
            } catch (\Exception $e) {
                $this->error("Failed to install $packageName. Error: " . $e->getMessage());
            }
        } else {
            $this->info("Package $packageName is already installed.");
        }
    }

    private function modifyGitignoreAndCleanUntracked(array $directories)
    {
        // Example implementation
        $gitignorePath = base_path('.gitignore');
        // $directories = collect(File::directories(base_path($directory)));

        foreach ($directories as  $directory) {
            $this->info("Updating .gitignore to ignore subdirectories of $directory...");

            if (strpos(file_get_contents($gitignorePath), $directory) === false) {
                file_put_contents($gitignorePath, PHP_EOL . $directory, FILE_APPEND);
                $this->info("Added $directory to .gitignore.");
            }
        }

        // Implement logic to remove or move untracked files as needed
        $this->info("Cleaning untracked files from $directory...");
        // Placeholder for actual cleaning logic
    }

    private function createServiceAndRegister($serviceName)
    {
        $this->info("Creating service: $serviceName...");

        // Define the path for the service directory and the service file itself
        $serviceDirectory = app_path('Services');
        $servicePath = $serviceDirectory . "/{$serviceName}.php";

        // Check if the Services directory exists, create it if not
        if (!file_exists($serviceDirectory)) {
            mkdir($serviceDirectory, 0777, true);
            $this->info("Services directory created.");
        }

        // Check if the service file already exists to avoid overwriting
        if (!file_exists($servicePath)) {
            // Attempt to create the service file
            try {
                File::put($servicePath, "<?php\n\nnamespace App\Services;\n\nclass $serviceName {}\n");
                $this->info("$serviceName created successfully.");

                $this->registerService();
            } catch (\Exception $e) {
                $this->error("An error occurred while creating $serviceName: " . $e->getMessage());
            }
        } else {
            $this->info("$serviceName already exists.");
        }
        $this->registerService();
    }
    function registerService()
    {
        $providerPath = app_path('Providers/AppServiceProvider.php');
        if (!File::exists($providerPath)) {
            $this->error('AppServiceProvider does not exist.');
            return;
        }

        $content = File::get($providerPath);

        // Simplified check if the service is already registered
        if (strpos($content, 'OpenAiAnalysisService::class') !== false) {
            $this->info('Service is already registered in AppServiceProvider.');
            return;
        }

        // Locate the register method by looking for its function declaration
        $registerMethodPos = strpos($content, 'function register()');
        if ($registerMethodPos === false) {
            $this->error('Could not locate the register method in AppServiceProvider.');
            return;
        }

        // Find the position of the next method or class closing brace after register method to insert our code before it
        $insertPos = strpos($content, '}', $registerMethodPos);
        if ($insertPos === false) {
            $this->error('Could not determine the correct position for service registration.');
            return;
        }

        // Prepare the code snippet for service registration
        $registrationCode = "\n        // Register OpenAiAnalysisService\n        \$this->app->singleton(\\App\\Services\\OpenAiAnalysisService::class, function (\$app) {\n            return new \\App\\Services\\OpenAiAnalysisService();\n        });\n";

        // Insert the service registration code before the closing brace of the register method
        $newContent = substr_replace($content, $registrationCode, $insertPos, 0);

        // Save the updated content
        File::put($providerPath, $newContent);
        $this->info('OpenAiAnalysisService has been registered in AppServiceProvider.');
    }
    private function addVariablesToConfig()
    {
        $configPath = config_path('app.php');
        if (!File::exists($configPath)) {
            $this->error('The config/app.php file does not exist.');
            return;
        }

        $content = File::get($configPath);

        // Prepare the new variables to be added
        $newVariables = "\n    'openAiKey' => env('OPEN_AI_KEY'),\n" .
            "    'waapiKey' => env('WAAPI_KEY'),\n" .
            "    'assistantId' => env('ASSISTANT_ID'),\n" .
            "    'waapiBaseUrl' => env('WAAPI_URL'),\n];";

        // Check if any of the new variables already exist to avoid duplication
        if (strpos($content, "'openAiKey'") !== false) {
            $this->info('It seems like the variables are already added.');
            return;
        }

        // Replace the closing bracket of the return statement with new variables
        $newContent = str_replace("\n];", $newVariables, $content);

        // Save the updated content back to the file
        File::put($configPath, $newContent);
        $this->info('New variables have been added to config/app.php successfully.');
    }

    private function runProcess($command)
    {
        $this->info("Executing command: " . implode(" ", $command));
        try {
            $process = new Process($command);
            $process->mustRun();

            echo $process->getOutput();
            $this->info("Command executed successfully.");
        } catch (ProcessFailedException $exception) {
            $this->error('Command failed: ' . $exception->getMessage());
        }
    }




























    private function addColumnsToMigration($modelName, $columns)
    {
        $migrationPath = $this->findMigrationFile($modelName);

        if ($migrationPath) {
            $this->info("Found migration file for $modelName: " . basename($migrationPath));

            $migrationContent = file_get_contents($migrationPath);
            // Pattern to find the place to insert columns (just before the closing of the up method)
            // Updated pattern to match inside the closure of Schema::create
            $pattern = '/(\$table->id\(\);)/';

            if (preg_match($pattern, $migrationContent)) {
                // Prepare the string to insert
                $replacement = "$1\n            " . implode("\n            ", $columns);
                $newMigrationContent = preg_replace($pattern, $replacement, $migrationContent, 1);

                // Check if replacement was successful
                if ($newMigrationContent && $newMigrationContent !== $migrationContent) {
                    file_put_contents($migrationPath, $newMigrationContent);
                    $this->info("Successfully added columns to " . basename($migrationPath));
                } else {
                    $this->error("Failed to modify " . basename($migrationPath) . ". The content may not have changed.");
                }
            } else {
                $this->error("Could not locate the up method's closing brace in " . basename($migrationPath));
            }
        } else {
            $this->error("Migration file for $modelName not found.");
        }
    }
    function findMigration($modelName)
    {

        $modelInstance = app("App\\Models\\$modelName");

        $tableName = $modelInstance->getTable();
        $migrationsPath = database_path('migrations');
        $migrationFiles = scandir($migrationsPath);

        $matchingMigrations = collect($migrationFiles)->filter(function ($file) use ($tableName) {

            return Str::contains($file, $tableName);
        });

        if ($matchingMigrations->isEmpty()) {
            $this->error("No migration found for the {$modelName} model.");
        } else {
            $this->info("Found migration(s) for the {$modelName} model:");
            foreach ($matchingMigrations as $migrationFile) {
                return $migrationFile;
            }
        }
    }
    private function findMigrationFile($modelName)
    {
        return database_path('migrations') . '\\' . $this->findMigration($modelName);
    }
}
