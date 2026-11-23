<?php

use App\Models\User;
use Hata\Actions\Maintenance\DeleteLogFile;
use Hata\Exceptions\MaintenanceException;
use Hata\Hata;
use Hata\Support\Maintenance\LogFiles;
use Hata\Support\Maintenance\LogReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();

    $this->logs = storage_path('framework/testing/logs-'.uniqid());
    File::ensureDirectoryExists($this->logs);
    app()->instance(LogFiles::class, new LogFiles($this->logs));
});

afterEach(function () {
    File::deleteDirectory($this->logs);
});

/**
 * Write a file into the scratch logs folder and return its path.
 */
function logFile(string $name, string $content = "line\n", ?int $modified = null): string
{
    $path = test()->logs.'/'.$name;
    File::put($path, $content);

    if ($modified !== null) {
        touch($path, $modified);
    }

    return $path;
}

function logReader(): User
{
    return User::factory()->withRole('super-admin')->create();
}

describe('access', function () {
    it('refuses the chunk route and the delete action without maintenance.run', function () {
        $path = logFile('laravel.log');
        $user = User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();

        $this->actingAs($user)->getJson(route('admin.maintenance.logs.chunk', md5('laravel.log')))->assertForbidden();

        expect(fn () => app(DeleteLogFile::class)->handle($user, 'laravel.log'))->toThrow(AuthorizationException::class)
            ->and(File::exists($path))->toBeTrue();
    });

    it('sends guests to the login page', function () {
        logFile('laravel.log');

        $this->get(route('admin.maintenance.logs.chunk', md5('laravel.log')))->assertRedirect(route('login'));
    });
});

describe('files', function () {
    it('lists the files of the folder, newest first, without dot files, folders and links', function () {
        logFile('laravel-2026-11-13.log', 'old', 1_000);
        logFile('laravel-2026-11-14.log', 'newer!', 2_000);
        logFile('worker.txt', '', 1_500);
        logFile('.gitignore');
        File::ensureDirectoryExists($this->logs.'/archive');
        File::put($this->logs.'/archive/inner.log', 'x');
        symlink(Hata::path('composer.json'), $this->logs.'/linked.log');

        $files = app(LogFiles::class)->all();

        expect(array_column($files, 'name'))->toBe(['laravel-2026-11-14.log', 'worker.txt', 'laravel-2026-11-13.log'])
            ->and($files[0]['size'])->toBe(6)
            ->and($files[0]['modified'])->toBe(2_000);
    });

    it('resolves only a name it lists', function (string $name) {
        logFile('laravel.log');
        logFile('.hidden');
        File::ensureDirectoryExists($this->logs.'/archive');
        File::put($this->logs.'/archive/inner.log', 'x');

        expect(app(LogFiles::class)->resolve($name))->toBeNull();
    })->with(['../.env', '..', '.hidden', 'archive/inner.log', 'archive', 'missing.log', '', '/etc/passwd', "laravel.log\0"]);

    it('resolves a listed name to its path', function () {
        $path = logFile('laravel.log');

        expect(app(LogFiles::class)->resolve('laravel.log'))->toBe($path);
    });

    it('is empty when the folder does not exist', function () {
        File::deleteDirectory($this->logs);

        expect(app(LogFiles::class)->all())->toBe([]);
    });
});

describe('reading', function () {
    it('reads a small file whole', function () {
        $path = logFile('laravel.log', "first\nsecond\n");

        $chunk = app(LogReader::class)->chunk($path);

        expect($chunk['start'])->toBe(0)
            ->and($chunk['end'])->toBe(13)
            ->and($chunk['size'])->toBe(13)
            ->and(strip_tags($chunk['html']))->toBe("first\nsecond\n");
    });

    it('reads a big file in chunks that start at a line and join back into the file', function () {
        $content = '';

        for ($line = 1; strlen($content) < LogReader::CHUNK * 2.5; $line++) {
            $content .= "line {$line} ".str_repeat('x', $line % 90)."\n";
        }

        $path = logFile('laravel.log', $content);
        $reader = app(LogReader::class);
        $before = null;
        $text = '';
        $chunks = 0;

        do {
            $chunk = $reader->chunk($path, $before);
            $plain = strip_tags($chunk['html']);

            expect(strlen($plain))->toBeLessThanOrEqual(LogReader::CHUNK)->and($plain)->toStartWith('line ');

            $text = $plain.$text;
            $before = $chunk['start'];
            $chunks++;
        } while ($before > 0);

        expect($text)->toBe($content)->and($chunks)->toBe(3);
    });

    it('keeps a full line when a chunk happens to start exactly at one', function () {
        $tail = str_repeat('b', LogReader::CHUNK - 1)."\n";
        $path = logFile('laravel.log', "head\n".$tail);

        $chunk = app(LogReader::class)->chunk($path);

        expect($chunk['start'])->toBe(5)->and(strip_tags($chunk['html']))->toBe($tail);
    });

    it('still moves through a line longer than a chunk', function () {
        $content = str_repeat('a', LogReader::CHUNK + 100)."\nlast\n";
        $path = logFile('laravel.log', $content);
        $reader = app(LogReader::class);

        $before = null;
        $text = '';
        $chunks = 0;

        do {
            $chunk = $reader->chunk($path, $before);
            $text = strip_tags($chunk['html']).$text;
            $before = $chunk['start'];
            $chunks++;
        } while ($before > 0 && $chunks < 10);

        expect($text)->toBe($content)->and($chunks)->toBe(3);
    });

    it('reads an empty file and a position past the end', function () {
        $empty = logFile('empty.log', '');
        $path = logFile('laravel.log', "one\n");
        $reader = app(LogReader::class);

        expect($reader->chunk($empty))->toMatchArray(['html' => '', 'start' => 0, 'end' => 0, 'size' => 0])
            ->and($reader->chunk($path, 999)['end'])->toBe(4)
            ->and($reader->chunk($path, -5))->toMatchArray(['html' => '', 'start' => 0, 'end' => 0]);
    });

    it('escapes markup and replaces invalid UTF-8', function () {
        $path = logFile('laravel.log', "<script>alert(1)</script> & \"quoted\"\nbad \xC3\x28 byte\n");

        $html = app(LogReader::class)->chunk($path)['html'];

        expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quoted&quot;')
            ->not->toContain('<script>')
            ->and(mb_check_encoding($html, 'UTF-8'))->toBeTrue()
            ->and(json_encode($html))->not->toBeFalse();
    });

    it('marks the date, the level and the stack trace lines', function () {
        $path = logFile('laravel.log', implode("\n", [
            '[2026-11-14 15:28:02] production.ERROR: Call to undefined method {"userId":1}',
            '[stacktrace]',
            '#0 /app/Actions/Media/UploadMedia.php(84): Hata\\Models\\Media->thumb()',
            '[2026-11-14T15:32:07.123456+00:00] local.WARNING: Probe failed',
            '[2026-11-14 15:33:00] local.DEBUG: select 1',
            'plain text',
            '',
        ]));

        $html = app(LogReader::class)->chunk($path)['html'];

        expect($html)
            ->toContain('<span class="log-date">[2026-11-14 15:28:02]</span> <span class="log-level log-level-error">production.ERROR:</span> Call to undefined method')
            ->toContain('<span class="log-trace">[stacktrace]</span>')
            ->toContain('<span class="log-trace">#0 /app/Actions/Media/UploadMedia.php(84): Hata\\Models\\Media-&gt;thumb()</span>')
            ->toContain('<span class="log-level log-level-warning">local.WARNING:</span>')
            ->toContain('<span class="log-level log-level-debug">local.DEBUG:</span>')
            ->toContain("\nplain text\n");
    });
});

describe('chunk route', function () {
    it('returns a chunk of a listed file', function () {
        logFile('laravel.log', "[2026-11-14 15:28:02] production.ERROR: Boom\n");

        $this->actingAs(logReader())->getJson(route('admin.maintenance.logs.chunk', md5('laravel.log')))
            ->assertOk()
            ->assertJson(['start' => 0, 'end' => 45, 'size' => 45, 'remaining' => null])
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'log-level-error'));
    });

    it('reads the part before a given position and says how much is above', function () {
        logFile('laravel.log', str_repeat("0123456789abcde\n", (int) (LogReader::CHUNK / 16) + 10));

        $response = $this->actingAs(logReader())->getJson(route('admin.maintenance.logs.chunk', md5('laravel.log')))->assertOk();

        expect($response->json('start'))->toBe(160)->and($response->json('remaining'))->toBeString();

        $this->getJson(route('admin.maintenance.logs.chunk', ['key' => md5('laravel.log'), 'before' => 160]))
            ->assertOk()
            ->assertJson(['start' => 0, 'end' => 160, 'remaining' => null]);
    });

    it('answers 404 for anything but the key of a listed file', function (string $key) {
        logFile('laravel.log');
        logFile('.hidden');

        $this->actingAs(logReader())->getJson('/admin/maintenance/logs/'.$key)->assertNotFound();
    })->with(['laravel.log', md5('missing.log'), md5('.hidden'), '..%2F.env', '%2E%2E']);

    it('opens a file whose name holds characters a URL treats specially', function (string $name) {
        logFile($name, "inside\n");

        $this->actingAs(logReader())->getJson(route('admin.maintenance.logs.chunk', md5($name)))
            ->assertOk()
            ->assertJsonPath('html', "inside\n");
    })->with(['a#b.log', 'a?b.log', 'a%41.log', "it's \"quoted\".log"]);

    it('rejects a position that is not a number', function () {
        logFile('laravel.log');

        $this->actingAs(logReader())->getJson(route('admin.maintenance.logs.chunk', ['key' => md5('laravel.log'), 'before' => 'abc']))
            ->assertUnprocessable();
    });
});

describe('deleting', function () {
    it('deletes a listed file', function () {
        $path = logFile('laravel.log');

        app(DeleteLogFile::class)->handle(logReader(), 'laravel.log');

        expect(File::exists($path))->toBeFalse();
    });

    it('refuses a name that is not listed and deletes nothing', function (string $name) {
        $path = logFile('laravel.log');
        $outside = storage_path('framework/testing/outside-'.uniqid().'.txt');
        File::put($outside, 'keep');
        test()->beforeApplicationDestroyed(fn () => File::delete($outside));

        expect(fn () => app(DeleteLogFile::class)->handle(logReader(), str_replace('{outside}', $outside, $name)))
            ->toThrow(MaintenanceException::class, __('maintenance.logs.errors.missing'))
            ->and(File::exists($path))->toBeTrue()
            ->and(File::exists($outside))->toBeTrue();
    })->with(['missing.log', '../'.'laravel.log', '{outside}', '.gitignore']);
});

describe('page', function () {
    it('lists the files on the logs tab only', function () {
        logFile('laravel-2026-11-14.log', str_repeat('x', 2048));

        Livewire::actingAs(logReader())->test('hata::admin.maintenance.index')
            ->assertDontSee('laravel-2026-11-14.log')
            ->set('tab', 'logs')
            ->assertSee('laravel-2026-11-14.log')
            ->assertSeeHtml('data-test="maintenance-logs"')
            ->set('tab', 'journal')
            ->assertDontSee('laravel-2026-11-14.log');
    });

    it('shows an empty state without files', function () {
        Livewire::withQueryParams(['tab' => 'logs'])->actingAs(logReader())->test('hata::admin.maintenance.index')
            ->assertSee(__('maintenance.logs.empty_title'))
            ->assertDontSeeHtml('data-test="maintenance-logs"');
    });

    it('opens a file in the viewer without putting its content into the page', function () {
        logFile('laravel.log', "secret line of the log\n");
        $page = Livewire::withQueryParams(['tab' => 'logs'])->actingAs(logReader())->test('hata::admin.maintenance.index');

        $page->call('openLog', app(LogFiles::class)->all()[0]['key'])
            ->assertSet('file', 'laravel.log')
            ->assertSeeHtml('data-test="maintenance-log-viewer"')
            ->assertSeeHtml(route('admin.maintenance.logs.chunk', md5('laravel.log')))
            ->assertDontSee('secret line of the log')
            ->call('closeLog')
            ->assertSet('file', '')
            ->assertSeeHtml('data-test="maintenance-logs"');
    });

    it('opens the file named in the address and falls back to the list for one that is gone', function () {
        logFile('laravel.log');

        Livewire::withQueryParams(['tab' => 'logs', 'file' => 'laravel.log'])->actingAs(logReader())->test('hata::admin.maintenance.index')
            ->assertSet('file', 'laravel.log')
            ->assertSeeHtml('data-test="maintenance-log-viewer"');

        Livewire::withQueryParams(['tab' => 'logs', 'file' => '../.env'])->actingAs(logReader())->test('hata::admin.maintenance.index')
            ->assertSet('file', '')
            ->assertSeeHtml('data-test="maintenance-logs"');

        Livewire::withQueryParams(['file' => 'laravel.log'])->actingAs(logReader())->test('hata::admin.maintenance.index')
            ->assertSet('file', '')
            ->assertSet('tab', 'actions');
    });

    it('ignores an unknown key', function () {
        logFile('laravel.log');

        Livewire::withQueryParams(['tab' => 'logs'])->actingAs(logReader())->test('hata::admin.maintenance.index')
            ->call('openLog', 'nope')
            ->assertSet('file', '')
            ->call('confirmDeleteLog', '../.env')
            ->assertSet('showDeleteLogModal', false);
    });

    it('deletes a file after the confirmation and returns to the list', function () {
        $path = logFile('laravel.log');
        $other = logFile('other.log');
        $key = app(LogFiles::class)->find('laravel.log')['key'];

        Livewire::withQueryParams(['tab' => 'logs', 'file' => 'laravel.log'])->actingAs(logReader())->test('hata::admin.maintenance.index')
            ->call('confirmDeleteLog', $key)
            ->assertSet('showDeleteLogModal', true)
            ->assertSee(__('maintenance.logs.delete.confirm', ['name' => 'laravel.log']))
            ->call('deleteLog')
            ->assertSet('showDeleteLogModal', false)
            ->assertSet('file', '')
            ->assertDontSee('laravel.log')
            ->assertSee('other.log');

        expect(File::exists($path))->toBeFalse()->and(File::exists($other))->toBeTrue();
    });

    it('says so when the file was already gone', function () {
        $path = logFile('laravel.log');
        $key = app(LogFiles::class)->find('laravel.log')['key'];

        $page = Livewire::withQueryParams(['tab' => 'logs'])->actingAs(logReader())->test('hata::admin.maintenance.index')
            ->call('confirmDeleteLog', $key);

        File::delete($path);

        $page->call('deleteLog')->assertSet('showDeleteLogModal', false)->assertOk();
    });

    it('keeps the viewer open when the file could not be deleted', function () {
        logFile('laravel.log');
        $key = app(LogFiles::class)->find('laravel.log')['key'];
        app()->bind(DeleteLogFile::class, fn () => new class(app(LogFiles::class)) extends DeleteLogFile
        {
            public function handle(User $actor, string $name): void
            {
                throw MaintenanceException::logFileNotDeleted();
            }
        });

        Livewire::withQueryParams(['tab' => 'logs', 'file' => 'laravel.log'])->actingAs(logReader())->test('hata::admin.maintenance.index')
            ->call('confirmDeleteLog', $key)
            ->call('deleteLog')
            ->assertSet('file', 'laravel.log')
            ->assertSeeHtml('data-test="maintenance-log-viewer"');
    });

    it('returns to the list for good when the open file disappeared', function () {
        $path = logFile('laravel.log');
        $key = app(LogFiles::class)->find('laravel.log')['key'];
        $page = Livewire::withQueryParams(['tab' => 'logs', 'file' => 'laravel.log'])->actingAs(logReader())->test('hata::admin.maintenance.index');

        File::delete($path);
        $page->call('confirmDeleteLog', $key)->assertSet('file', '')->assertSet('showDeleteLogModal', false);

        logFile('laravel.log');
        $page->call('closeLog')->assertSet('file', '')->assertSeeHtml('data-test="maintenance-logs"');
    });

    it('does not read the folder on the other tabs', function () {
        $files = new class($this->logs) extends LogFiles
        {
            public int $listed = 0;

            public function all(): array
            {
                $this->listed++;

                return parent::all();
            }
        };
        app()->instance(LogFiles::class, $files);

        Livewire::actingAs(logReader())->test('hata::admin.maintenance.index')->set('tab', 'journal');

        expect($files->listed)->toBe(0);
    });
});
