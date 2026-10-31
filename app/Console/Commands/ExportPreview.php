<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Appointments\Queries\AgendaAppointments;
use App\Http\Resources\AgendaEventResource;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ExternalForm;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

final class ExportPreview extends Command
{
    protected $signature = 'preview:export
        {url : Public address of the preview, e.g. https://user.github.io/Agenda-Contabil}
        {--output=build/preview : Directory that receives the static site}';

    protected $description = 'Export a read-only static preview of the application with the demo data';

    public function handle(Kernel $kernel, Filesystem $files): int
    {
        // The preview is public: it must never be built from real people's data.
        if ($this->laravel->isProduction()) {
            $this->components->error('The preview is built from demo data only and cannot run in production.');

            return self::FAILURE;
        }

        $base = rtrim((string) $this->argument('url'), '/');
        $output = base_path((string) $this->option('output'));
        $admin = User::query()->where('email', 'admin@agenda.local')->first();

        $realContacts = Client::query()
            ->whereNotNull('email')
            ->whereRaw('email !~* ?', ['@([a-z0-9-]+\\.)*example\\.(com|org|net)$'])
            ->exists();
        // Clients without e-mail slip past the check above; a copy of the real
        // database is still given away by its staff accounts.
        $realStaff = User::query()->where('email', 'not like', '%@agenda.local')->exists();

        if ($realContacts || $realStaff || ExternalForm::query()->exists()) {
            $this->components->error('Found real contacts or external forms. The preview is built from the demo seed only.');

            return self::FAILURE;
        }

        if ($admin === null || Appointment::query()->doesntExist()) {
            $this->components->error('Seed the demo data first: php artisan migrate:fresh --seed');

            return self::FAILURE;
        }

        config(['services.forms.enabled' => false]);

        // The internal requests are plain HTTP; links must use the published scheme.
        URL::forceRootUrl($base);
        URL::forceScheme((string) parse_url($base, PHP_URL_SCHEME));
        URL::useAssetOrigin($base);

        $files->deleteDirectory($output);
        $files->ensureDirectoryExists($output);

        $login = $this->render($kernel, '/login', null, $base);
        $this->write($files, $output, '/', $login);
        $this->write($files, $output, '/login', $login);

        $dashboard = $this->render($kernel, '/dashboard', $admin, $base);
        $this->write($files, $output, '/dashboard', $dashboard);

        foreach ($this->privatePages() as $path) {
            $this->write($files, $output, $path, $this->render($kernel, $path, $admin, $base));
        }

        $this->write($files, $output, '/boas-vindas', $this->withCurtain(
            $dashboard,
            __('session.welcome_title', ['name' => Str::before($admin->name, ' ')]),
            __('session.welcome_subtitle'),
        ));
        $this->write($files, $output, '/sessao-encerrada', $this->withCurtain(
            $login,
            __('session.signed_out_title'),
            __('session.signed_out_subtitle'),
            'curtain--quick',
        ));

        $files->put("{$output}/agenda/eventos.json", $this->agendaFeed($admin));
        $files->copyDirectory(public_path('build'), "{$output}/build");
        $files->copy(public_path('favicon.ico'), "{$output}/favicon.ico");
        $files->put("{$output}/.nojekyll", '');

        $this->components->info("Preview written to {$output}");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function privatePages(): array
    {
        $clients = Client::query()->pluck('id');

        return [
            '/agenda',
            '/impacto',
            '/atendidos',
            '/atendidos/novo',
            '/atendimentos/novo',
            '/perfil',
            '/configuracoes/servicos',
            ...$clients->map(fn (int $id): string => "/atendidos/{$id}"),
            ...$clients->map(fn (int $id): string => "/atendidos/{$id}/editar"),
            ...Appointment::query()->pluck('id')->map(fn (int $id): string => "/atendimentos/{$id}"),
        ];
    }

    private function render(Kernel $kernel, string $path, ?User $user, string $base): string
    {
        $user === null ? Auth::guard('web')->forgetUser() : Auth::guard('web')->setUser($user);

        $request = Request::create($path);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        if (! $response->isOk()) {
            throw new RuntimeException("{$path} answered HTTP {$response->getStatusCode()}.");
        }

        return $this->forStaticHosting((string) $response->getContent(), $base);
    }

    private function forStaticHosting(string $html, string $base): string
    {
        $html = preg_replace(
            '/<html([^>]*)>/',
            '<html$1 data-preview data-preview-base="'.e($base).'">',
            $html,
            1,
        );

        $html = preg_replace('/data-events-url="[^"]*"/', 'data-events-url="'.e($base).'/agenda/eventos.json"', $html);
        $html = preg_replace('/data-create-url="[^"]*"/', 'data-create-url=""', $html);

        $html = preg_replace_callback(
            '/<(input|select)\b[^>]*data-client-(search|type)[^>]*>/',
            fn (array $tag): string => preg_replace('/\swire:model[\w.-]*="[^"]*"/', '', $tag[0]),
            $html,
        );

        return str_replace('</body>', view('preview.banner')->render().'</body>', $html);
    }

    private function withCurtain(string $html, string $title, string $subtitle, string $class = ''): string
    {
        $curtain = Blade::render(
            '<x-curtain :title="$title" :subtitle="$subtitle" :class="$class" />',
            ['title' => $title, 'subtitle' => $subtitle, 'class' => $class],
        );

        return str_replace('</body>', $curtain.'</body>', $html);
    }

    private function agendaFeed(User $admin): string
    {
        $request = Request::create('/agenda/eventos');
        $request->setUserResolver(fn (): User => $admin);

        $events = AgendaEventResource::collection(AgendaAppointments::between(now()->subDays(90), now()->addDays(90)))
            ->resolve($request);

        return (string) json_encode(
            array_map(fn (array $event): array => [...$event, 'editable' => false], $events),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function write(Filesystem $files, string $output, string $path, string $html): void
    {
        $directory = rtrim($output.$path, '/');

        $files->ensureDirectoryExists($directory);
        $files->put("{$directory}/index.html", $html);
    }
}
