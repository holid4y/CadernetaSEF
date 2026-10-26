<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ExternalForm;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $output = 'storage/framework/testing/preview';

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(base_path($this->output));

        parent::tearDown();
    }

    public function test_the_preview_is_rendered_for_the_published_address(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('preview:export', ['url' => 'https://example.github.io/Agenda', '--output' => $this->output])
            ->assertSuccessful();

        $dashboard = file_get_contents(base_path("{$this->output}/dashboard/index.html"));
        $feed = file_get_contents(base_path("{$this->output}/agenda/eventos.json"));

        $this->assertStringContainsString('href="https://example.github.io/Agenda/agenda"', $dashboard);
        $this->assertStringNotContainsString('http://example.github.io', $dashboard.$feed);
        $this->assertStringContainsString('data-preview', $dashboard);
        $this->assertFileExists(base_path("{$this->output}/index.html"));
        $this->assertStringContainsString('"editable":false', $feed);

        $clients = file_get_contents(base_path("{$this->output}/atendidos/index.html"));
        $this->assertMatchesRegularExpression('/<input[^>]*data-client-search/', $clients);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-client-search[^>]*wire:model/', $clients);
    }

    public function test_the_sign_in_and_sign_out_curtains_exist_without_a_server(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('preview:export', ['url' => 'https://example.github.io/Agenda', '--output' => $this->output])
            ->assertSuccessful();

        $welcome = file_get_contents(base_path("{$this->output}/boas-vindas/index.html"));
        $signedOut = file_get_contents(base_path("{$this->output}/sessao-encerrada/index.html"));

        $this->assertStringContainsString('curtain--leave', $welcome);
        $this->assertStringContainsString('Olá, Administrador', $welcome);
        $this->assertStringContainsString('Sessão encerrada', $signedOut);
        $this->assertStringContainsString('curtain--quick', $signedOut);
    }

    public function test_it_refuses_to_publish_real_contacts(): void
    {
        $this->seed(DatabaseSeeder::class);
        Client::factory()->create(['email' => 'maria.souza@gmail.com']);

        $this->artisan('preview:export', ['url' => 'https://example.github.io/Agenda', '--output' => $this->output])
            ->assertFailed();

        $this->assertDirectoryDoesNotExist(base_path($this->output));
    }

    public function test_it_refuses_a_copy_of_the_real_database_even_without_client_e_mails(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::factory()->create(['email' => 'coordenacao@feevale.br']);
        Client::factory()->create(['email' => null]);

        $this->artisan('preview:export', ['url' => 'https://example.github.io/Agenda', '--output' => $this->output])
            ->assertFailed();

        $this->assertDirectoryDoesNotExist(base_path($this->output));
    }

    public function test_it_refuses_to_publish_from_a_production_database(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('preview:export', ['url' => 'https://example.github.io/Agenda', '--output' => $this->output])
            ->assertFailed();

        $this->assertDirectoryDoesNotExist(base_path($this->output));
    }

    public function test_it_refuses_to_publish_external_form_metadata(): void
    {
        $this->seed(DatabaseSeeder::class);
        (new ExternalForm)->forceFill([
            'external_id' => 'private-partner-form',
            'title' => 'Private form',
            'status' => 'pending',
            'source_version' => 1,
            'source_updated_at' => now(),
            'snapshot_hash' => hash('sha256', 'fixture'),
        ])->save();

        $this->artisan('preview:export', ['url' => 'https://example.github.io/Agenda', '--output' => $this->output])->assertFailed();
        $this->assertDirectoryDoesNotExist(base_path($this->output));
    }
}
