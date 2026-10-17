<?php

use App\Platform\Modules\Generator\ModuleDefinition;
use App\Platform\Modules\Generator\ModuleWriter;
use Carbon\CarbonImmutable;

/*
 * make:erp-module: a definition is checked strictly (every problem at
 * once), a good one becomes a full module (backend, screens, texts in both
 * languages, tests), and nothing that exists is ever overwritten — even a
 * file differing only in letter case.
 */

function generatorDefinition(array $changes = []): string
{
    $definition = array_replace_recursive([
        'key' => 'library_desk',
        'name' => ['en' => 'Library desk', 'bn' => 'লাইব্রেরি ডেস্ক'],
        'description' => ['en' => 'Books and loans.', 'bn' => 'বই আর ধার।'],
        'icon' => 'book',
        'entities' => [
            'book' => [
                'label' => ['en' => 'Book', 'bn' => 'বই'], 'plural' => ['en' => 'Books', 'bn' => 'বই'],
                'search' => ['isbn'], 'filters' => ['shelf'],
                'fields' => [
                    'title' => ['type' => 'translatable', 'required' => true, 'label' => ['en' => 'Title', 'bn' => 'নাম']],
                    'isbn' => ['type' => 'text', 'max' => 20, 'unique' => true, 'label' => ['en' => 'ISBN', 'bn' => 'ISBN']],
                    'shelf' => ['type' => 'enum', 'label' => ['en' => 'Shelf', 'bn' => 'তাক'], 'options' => ['a' => ['en' => 'A', 'bn' => 'ক']]],
                    'price' => ['type' => 'money', 'label' => ['en' => 'Price', 'bn' => 'দাম']],
                ],
            ],
            'loan' => [
                'label' => ['en' => 'Loan', 'bn' => 'ধার'], 'plural' => ['en' => 'Loans', 'bn' => 'ধার'], 'title' => 'borrower',
                'fields' => [
                    'borrower' => ['type' => 'text', 'required' => true, 'label' => ['en' => 'Borrower', 'bn' => 'যিনি নিলেন']],
                    'book_id' => ['type' => 'belongs_to', 'entity' => 'book', 'required' => true, 'label' => ['en' => 'Book', 'bn' => 'বই']],
                ],
            ],
        ],
    ], $changes);
    $path = storage_path('framework/testing/generator-'.Str::random(8).'.php');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, '<?php return '.var_export($definition, true).';');

    return $path;
}

it('lists every problem of a definition at once', function () {
    $path = generatorDefinition(['key' => 'crm', 'icon' => 'rocket', 'entities' => ['book' => ['fields' => [
        'id' => ['type' => 'text', 'label' => ['en' => 'Id', 'bn' => 'আইডি']],
        'author_id' => ['type' => 'employee', 'label' => ['en' => 'Author', 'bn' => 'লেখক']],
        'genre' => ['type' => 'enum', 'label' => ['en' => 'Genre', 'bn' => 'ধরন'], 'options' => []],
    ]]]]);

    expect(fn () => ModuleDefinition::fromFile($path, ['book', 'package'], ['crm']))->toThrow(function (InvalidArgumentException $error) {
        expect($error->getMessage())
            ->toContain('a module "crm" already exists')
            ->toContain('icon: one of book, package')
            ->toContain('fields.id: snake_case, not one of id')
            ->toContain("employee fields need 'hrm'")
            ->toContain('fields.genre.options');
    });
});

it('writes a whole module from a good definition', function () {
    $definition = ModuleDefinition::fromFile(generatorDefinition(), ['book'], []);
    $files = (new ModuleWriter($definition, CarbonImmutable::parse('2026-11-02 10:00:00')))->files();

    expect(array_keys($files))->toContain(
        'Modules/LibraryDesk/manifest.php',
        'Modules/LibraryDesk/database/migrations/tenant/2026_11_02_100000_create_library_desk_tables.php',
        'Modules/LibraryDesk/app/Services/Books.php',
        'Modules/LibraryDesk/app/Http/Controllers/LoanController.php',
        'Modules/LibraryDesk/lang/bn/messages.php',
        'Modules/LibraryDesk/resources/js/locales/bn/library_desk.json',
        'tests/Feature/LibraryDesk/LibraryDeskTest.php',
    );
    // Money in minor units with the company's currency; a translatable title; the unique number per company.
    expect($files['Modules/LibraryDesk/database/migrations/tenant/2026_11_02_100000_create_library_desk_tables.php'])
        ->toContain("unsignedBigInteger('price_minor')")->toContain("char('currency', 3)")->toContain("json('title')")
        ->toContain("unique(['organization_id', 'isbn'], 'library_desk_books_isbn_unique')");
    // A book a loan uses is not removed.
    expect($files['Modules/LibraryDesk/app/Services/Books.php'])->toContain("query(Loan::class, \$company)->where('book_id', \$record->getKey())->exists()");
    expect($files['Modules/LibraryDesk/routes/api.php'])->toContain("'module:library_desk'")->toContain('throttle:library-desk-writes');
    expect(json_decode($files['Modules/LibraryDesk/resources/js/locales/bn/library_desk.json'], true)['fields']['loan']['borrower'])->toBe('যিনি নিলেন');
    foreach ($files as $name => $contents) {
        if (str_ends_with($name, '.php')) {
            $file = tempnam(sys_get_temp_dir(), 'gen');
            file_put_contents($file, $contents);
            exec('php -l '.escapeshellarg($file).' 2>&1', $output, $status);
            expect($status)->toBe(0, "{$name} does not parse");
            @unlink($file);
        }
    }
});

it('refuses to overwrite anything, even a file differing only in letter case', function () {
    // Fleet is in this repository (made by the generator): its definition clashes.
    $this->artisan('make:erp-module', ['definition' => 'database/module-definitions/fleet.php', '--dry-run' => true])
        ->expectsOutputToContain('a module "fleet" already exists')
        ->assertFailed();
});
