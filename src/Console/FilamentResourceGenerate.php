<?php

namespace TomatoPHP\FilamentPlugins\Console;

use Coolsam\Modules\Facades\FilamentModules;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Forms\Commands\Concerns\CanGenerateForms;
use Filament\Panel;
use Filament\Support\Commands\Concerns\CanIndentStrings;
use Filament\Support\Commands\Concerns\CanManipulateFiles;
use Filament\Support\Commands\Concerns\CanReadModelSchemas;
use Filament\Tables\Commands\Concerns\CanGenerateTables;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class FilamentResourceGenerate extends Command
{
    use CanGenerateForms;
    use CanGenerateTables;
    use CanIndentStrings;
    use CanManipulateFiles;
    use CanReadModelSchemas;

    protected $signature = 'filament-plugins:resource {name?} {module?} {--model-namespace=} {--soft-deletes} {--view} {--G|generate} {--S|simple} {--panel=} {--F|force}';

    protected $description = 'Create a new Filament resource in a module';

    public function handle(): int
    {
        $moduleName = $this->argument('module') ?? text('In which Module should we create the resource?', 'e.g Blog', required: true);
        $moduleStudlyName = str($moduleName)->studly()->toString();
        $module = Module::find($moduleName);
        $appPath = 'app';
        $moduleDir = File::directories($module->getPath());
        if(in_array($module->getPath() .'/src', $moduleDir)){
            $appPath = 'src';
        }
        $modelNamespace = $this->option('model-namespace') ?? $module->appNamespace('Models');
        $modelNamespace = str($modelNamespace)->rtrim('\\')->toString();

        // Copied from make:filament-resource and modified by smaosa
        $model = (string) str($this->argument('name') ?? text(
            label: 'What is the model name?',
            placeholder: 'BlogPost',
            required: true,
        ))
            ->studly()
            ->beforeLast('Resource')
            ->trim('/')
            ->trim('\\')
            ->trim(' ')
            ->studly()
            ->replace('/', '\\');

        if (blank($model)) {
            $model = 'Resource';
        }

        $modelClass = (string) str($model)->afterLast('\\');
        $modelSubNamespace = str($model)->contains('\\') ?
            (string) str($model)->beforeLast('\\') :
            '';
        $pluralModelClass = (string) str($modelClass)->pluralStudly();
        $needsAlias = $modelClass === 'Record';

        $panel = $this->option('panel');

        if ($panel) {
            $panel = Filament::getPanel($panel);
        }

        if (! $panel) {
            $panels = Filament::getPanels();

            /** @var Panel $panel */
            $panel = (count($panels) > 1) ? $panels[select(
                label: 'Which panel would you like to create this in?',
                options: array_map(
                    fn (Panel $panel): string => $panel->getId(),
                    $panels,
                ),
                default: Filament::getDefaultPanel()->getId()
            )] : Arr::first($panels);
        }

        $resourceDirectories = collect($panel->getResourceDirectories())->filter(fn ($directory) => str($directory)->contains($module->appPath()))->values()->toArray();
        $resourceNamespaces = collect($panel->getResourceNamespaces())->filter(fn ($namespace) => str($namespace)->contains($module->appNamespace()))->values()->toArray();

        //Create Directory For Selected Panel in Module If Not Exists
        //use `rtrim` to ensure no slash doubled
        if(count($resourceDirectories) < 1 && count($resourceNamespaces) < 1){
            $modulePath = module_path($moduleName);
            if(!File::exists(rtrim($modulePath, '/') . '/'.rtrim($appPath, '/') . '//Filament/'.Str::studly($panel->getId()))){
                File::makeDirectory(rtrim($modulePath, '/') . '/'.rtrim($appPath, '/') . '//Filament/'.Str::studly($panel->getId()));
            }
            if(!File::exists(rtrim($modulePath, '/')  . '/'.rtrim($appPath, '/') . '//Filament/'.Str::studly($panel->getId()).'/Resources')){
                File::makeDirectory(rtrim($modulePath, '/')  . '/'.rtrim($appPath, '/') . '//Filament/'.Str::studly($panel->getId()).'/Resources');
            }
            $resourceDirectories[] = $modulePath . '/'.$appPath . '//Filament/'.Str::studly($panel->getId()).'/Resources';
            $resourceNamespaces[] = $module->appNamespace().'\\Filament\\'.Str::studly($panel->getId()).'\\Resources';
        }


        $namespace = (count($resourceNamespaces) > 1) ?
            select(
                label: 'Which namespace would you like to create this in?',
                options: $resourceNamespaces
            ) :
            (Arr::first($resourceNamespaces) ?? $module->appNamespace('Filament\\Resources'));
        $path = (count($resourceDirectories) > 1) ?
            $resourceDirectories[array_search($namespace, $resourceNamespaces)] :
            (Arr::first($resourceDirectories) ?? $module->appPath('Filament/Resources/'));

        $resource = "{$model}Resource";
        $resourceClass = "{$modelClass}Resource";
        $resourceNamespace = $modelSubNamespace;
        $namespace .= $resourceNamespace !== '' ? "\\{$resourceNamespace}" : '';
        $listResourcePageClass = "List{$pluralModelClass}";
        $manageResourcePageClass = "Manage{$pluralModelClass}";
        $createResourcePageClass = "Create{$modelClass}";
        $editResourcePageClass = "Edit{$modelClass}";
        $viewResourcePageClass = "View{$modelClass}";

        $baseResourcePath =
            (string) str($resource)
                ->prepend('/')
                ->prepend($path)
                ->replace('\\', '/')
                ->replace('//', '/');

        $resourcePath = "{$baseResourcePath}.php";
        $resourcePagesDirectory = "{$baseResourcePath}/Pages";
        $listResourcePagePath = "{$resourcePagesDirectory}/{$listResourcePageClass}.php";
        $manageResourcePagePath = "{$resourcePagesDirectory}/{$manageResourcePageClass}.php";
        $createResourcePagePath = "{$resourcePagesDirectory}/{$createResourcePageClass}.php";
        $editResourcePagePath = "{$resourcePagesDirectory}/{$editResourcePageClass}.php";
        $viewResourcePagePath = "{$resourcePagesDirectory}/{$viewResourcePageClass}.php";

        if (! $this->option('force') && $this->checkForCollision([
            $resourcePath,
            $listResourcePagePath,
            $manageResourcePagePath,
            $createResourcePagePath,
            $editResourcePagePath,
            $viewResourcePagePath,
        ])) {
            return static::INVALID;
        }

        $pages = '';
        $pages .= '\'index\' => Pages\\' . ($this->option('simple') ? $manageResourcePageClass : $listResourcePageClass) . '::route(\'/\'),';

        if (! $this->option('simple')) {
            $pages .= PHP_EOL . "'create' => Pages\\{$createResourcePageClass}::route('/create'),";

            if ($this->option('view')) {
                $pages .= PHP_EOL . "'view' => Pages\\{$viewResourcePageClass}::route('/{record}'),";
            }

            $pages .= PHP_EOL . "'edit' => Pages\\{$editResourcePageClass}::route('/{record}/edit'),";
        }

        $tableActions = [];

        if ($this->option('view')) {
            $tableActions[] = 'Tables\Actions\ViewAction::make(),';
        }

        $tableActions[] = 'Tables\Actions\EditAction::make(),';

        $relations = '';

        if ($this->option('simple')) {
            $tableActions[] = 'Tables\Actions\DeleteAction::make(),';

            if ($this->option('soft-deletes')) {
                $tableActions[] = 'Tables\Actions\ForceDeleteAction::make(),';
                $tableActions[] = 'Tables\Actions\RestoreAction::make(),';
            }
        } else {
            $relations .= PHP_EOL . 'public static function getRelations(): array';
            $relations .= PHP_EOL . '{';
            $relations .= PHP_EOL . '    return [';
            $relations .= PHP_EOL . '        //';
            $relations .= PHP_EOL . '    ];';
            $relations .= PHP_EOL . '}' . PHP_EOL;
        }

        $tableActions = implode(PHP_EOL, $tableActions);

        $tableBulkActions = [];

        $tableBulkActions[] = 'Tables\Actions\DeleteBulkAction::make(),';

        $eloquentQuery = '';

        if ($this->option('soft-deletes')) {
            $tableBulkActions[] = 'Tables\Actions\ForceDeleteBulkAction::make(),';
            $tableBulkActions[] = 'Tables\Actions\RestoreBulkAction::make(),';

            $eloquentQuery .= PHP_EOL . PHP_EOL . 'public static function getEloquentQuery(): Builder';
            $eloquentQuery .= PHP_EOL . '{';
            $eloquentQuery .= PHP_EOL . '    return parent::getEloquentQuery()';
            $eloquentQuery .= PHP_EOL . '        ->withoutGlobalScopes([';
            $eloquentQuery .= PHP_EOL . '            SoftDeletingScope::class,';
            $eloquentQuery .= PHP_EOL . '        ]);';
            $eloquentQuery .= PHP_EOL . '}';
        }

        $tableBulkActions = implode(PHP_EOL, $tableBulkActions);

        $potentialCluster = (string) str($namespace)->beforeLast('\Resources');
        $clusterAssignment = null;
        $clusterImport = null;

        if (
            class_exists($potentialCluster) &&
            is_subclass_of($potentialCluster, Cluster::class)
        ) {
            $clusterAssignment = $this->indentString(PHP_EOL . PHP_EOL . 'protected static ?string $cluster = ' . class_basename($potentialCluster) . '::class;');
            $clusterImport = "use {$potentialCluster};" . PHP_EOL;
        }

        $this->copyStubToApp('Resource', $resourcePath, [
            'clusterAssignment' => $clusterAssignment,
            'clusterImport' => $clusterImport,
            'eloquentQuery' => $this->indentString($eloquentQuery, 1),
            'formSchema' => $this->indentString($this->option('generate') ? $this->getResourceFormSchema(
                $modelNamespace . ($modelSubNamespace !== '' ? "\\{$modelSubNamespace}" : '') . '\\' . $modelClass,
            ) : '//', 4),
            'model' => ($model === 'Resource') ? "{$modelNamespace}\\Resource as ResourceModel" : "{$modelNamespace}\\{$model}",
            'modelClass' => ($model === 'Resource') ? 'ResourceModel' : $modelClass,
            'namespace' => $namespace,
            'pages' => $this->indentString($pages, 3),
            'relations' => $this->indentString($relations, 1),
            'resource' => "{$namespace}\\{$resourceClass}",
            'resourceClass' => $resourceClass,
            'tableActions' => $this->indentString($tableActions, 4),
            'tableBulkActions' => $this->indentString($tableBulkActions, 5),
            'tableColumns' => $this->indentString($this->option('generate') ? $this->getResourceTableColumns(
                $modelNamespace . ($modelSubNamespace !== '' ? "\\{$modelSubNamespace}" : '') . '\\' . $modelClass,
            ) : '//', 4),
            'tableFilters' => $this->indentString(
                $this->option('soft-deletes') ? 'Tables\Filters\TrashedFilter::make(),' : '//',
                4,
            ),
        ]);

        if ($this->option('simple')) {
            $this->copyStubToApp('ResourceManagePage', $manageResourcePagePath, [
                'baseResourcePage' => 'Filament\\Resources\\Pages\\ManageRecords' . ($needsAlias ? ' as BaseManageRecords' : ''),
                'baseResourcePageClass' => $needsAlias ? 'BaseManageRecords' : 'ManageRecords',
                'namespace' => "{$namespace}\\{$resourceClass}\\Pages",
                'resource' => "{$namespace}\\{$resourceClass}",
                'resourceClass' => $resourceClass,
                'resourcePageClass' => $manageResourcePageClass,
            ]);
        } else {
            $this->copyStubToApp('ResourceListPage', $listResourcePagePath, [
                'baseResourcePage' => 'Filament\\Resources\\Pages\\ListRecords' . ($needsAlias ? ' as BaseListRecords' : ''),
                'baseResourcePageClass' => $needsAlias ? 'BaseListRecords' : 'ListRecords',
                'namespace' => "{$namespace}\\{$resourceClass}\\Pages",
                'resource' => "{$namespace}\\{$resourceClass}",
                'resourceClass' => $resourceClass,
                'resourcePageClass' => $listResourcePageClass,
            ]);

            $this->copyStubToApp('ResourcePage', $createResourcePagePath, [
                'baseResourcePage' => 'Filament\\Resources\\Pages\\CreateRecord' . ($needsAlias ? ' as BaseCreateRecord' : ''),
                'baseResourcePageClass' => $needsAlias ? 'BaseCreateRecord' : 'CreateRecord',
                'namespace' => "{$namespace}\\{$resourceClass}\\Pages",
                'resource' => "{$namespace}\\{$resourceClass}",
                'resourceClass' => $resourceClass,
                'resourcePageClass' => $createResourcePageClass,
            ]);

            $editPageActions = [];

            if ($this->option('view')) {
                $this->copyStubToApp('ResourceViewPage', $viewResourcePagePath, [
                    'baseResourcePage' => 'Filament\\Resources\\Pages\\ViewRecord' . ($needsAlias ? ' as BaseViewRecord' : ''),
                    'baseResourcePageClass' => $needsAlias ? 'BaseViewRecord' : 'ViewRecord',
                    'namespace' => "{$namespace}\\{$resourceClass}\\Pages",
                    'resource' => "{$namespace}\\{$resourceClass}",
                    'resourceClass' => $resourceClass,
                    'resourcePageClass' => $viewResourcePageClass,
                ]);

                $editPageActions[] = 'Actions\ViewAction::make(),';
            }

            $editPageActions[] = 'Actions\DeleteAction::make(),';

            if ($this->option('soft-deletes')) {
                $editPageActions[] = 'Actions\ForceDeleteAction::make(),';
                $editPageActions[] = 'Actions\RestoreAction::make(),';
            }

            $editPageActions = implode(PHP_EOL, $editPageActions);

            $this->copyStubToApp('ResourceEditPage', $editResourcePagePath, [
                'baseResourcePage' => 'Filament\\Resources\\Pages\\EditRecord' . ($needsAlias ? ' as BaseEditRecord' : ''),
                'baseResourcePageClass' => $needsAlias ? 'BaseEditRecord' : 'EditRecord',
                'actions' => $this->indentString($editPageActions, 3),
                'namespace' => "{$namespace}\\{$resourceClass}\\Pages",
                'resource' => "{$namespace}\\{$resourceClass}",
                'resourceClass' => $resourceClass,
                'resourcePageClass' => $editResourcePageClass,
            ]);
        }

        $this->components->info("Filament resource [{$resourcePath}] created successfully.");

        return static::SUCCESS;
    }

    protected function getDefaultStubPath(): string
    {
        return base_path('vendor/filament/filament/stubs');
    }
}
