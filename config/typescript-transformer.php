<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptCollector;
use Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer;
use Spatie\LaravelTypeScriptTransformer\Transformers\DtoTransformer;
use Spatie\LaravelTypeScriptTransformer\Transformers\SpatieStateTransformer;
use Spatie\TypeScriptTransformer\Collectors\DefaultCollector;
use Spatie\TypeScriptTransformer\Collectors\EnumCollector;
use Spatie\TypeScriptTransformer\Formatters\PrettierFormatter;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;

return [
    /*
    |--------------------------------------------------------------------------
    | Auto Discover Types
    |--------------------------------------------------------------------------
    |
    | The package will search these folders for PHP classes that can be converted
    | to TypeScript. We keep app_path() because our modules are inside app/.
    |
    */

    'auto_discover_types' => [
        app_path(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Collectors
    |--------------------------------------------------------------------------
    |
    | Collectors decide which PHP classes should be converted.
    | DataTypeScriptCollector is for Spatie Laravel Data classes.
    | DefaultCollector is for classes marked with #[TypeScript] or @typescript.
    | EnumCollector is for PHP enums.
    |
    */

    'collectors' => [
        DataTypeScriptCollector::class,
        DefaultCollector::class,
        EnumCollector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transformers
    |--------------------------------------------------------------------------
    |
    | Transformers decide how PHP classes/enums are converted into TypeScript.
    | DataTypeScriptTransformer is important for Spatie Laravel Data classes.
    |
    */

    'transformers' => [
        DataTypeScriptTransformer::class,
        SpatieStateTransformer::class,
        EnumTransformer::class,
        DtoTransformer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Type Replacements
    |--------------------------------------------------------------------------
    |
    | JavaScript does not understand PHP Carbon objects directly.
    | So Carbon/date values become string types in TypeScript.
    |
    */

    'default_type_replacements' => [
        DateTimeImmutable::class => 'string',
        DateTimeInterface::class => 'string',
        CarbonInterface::class => 'string',
        CarbonImmutable::class => 'string',
        Carbon::class => 'string',
    ],

    /*
    |--------------------------------------------------------------------------
    | Output File
    |--------------------------------------------------------------------------
    |
    | This is where generated TypeScript types will be saved.
    |
    */

    'output_file' => resource_path('js/types/generated.ts'),

    /*
    |--------------------------------------------------------------------------
    | Writer
    |--------------------------------------------------------------------------
    |
    | ModuleWriter means the generated file will use export statements.
    | This is better for Vue/Inertia TypeScript imports.
    |
    */

    'writer' => ModuleWriter::class,

    /*
    |--------------------------------------------------------------------------
    | Formatter
    |--------------------------------------------------------------------------
    |
    | This formats the generated TypeScript file using Prettier.
    |
    */

    'formatter' => PrettierFormatter::class,

    /*
    |--------------------------------------------------------------------------
    | Native Enums
    |--------------------------------------------------------------------------
    */

    'transform_to_native_enums' => true,

    /*
    |--------------------------------------------------------------------------
    | Optional Nulls
    |--------------------------------------------------------------------------
    |
    | If PHP property is nullable, TypeScript can make it optional.
    |
    */

    'transform_null_to_optional' => true,
];
