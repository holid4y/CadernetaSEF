<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Clients\ClientIndex;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_sends_the_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_inline_scripts_carry_the_nonce_announced_in_the_policy(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $match);
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/', $response->getContent(), $inlineScripts);

        $this->assertNotEmpty($match[1]);
        $this->assertNotEmpty($inlineScripts[1]);

        foreach ($inlineScripts[1] as $attributes) {
            $this->assertStringContainsString('nonce="'.$match[1].'"', $attributes);
        }

        $this->assertStringNotContainsString('onclick=', $response->getContent());
    }

    public function test_pages_with_personal_data_are_never_cached(): void
    {
        $cacheControl = $this->actingAs(User::factory()->create())
            ->get('/atendidos')
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_a_session_ends_as_soon_as_the_account_is_deactivated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['active' => false]);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_documents_and_notes_are_encrypted_at_rest(): void
    {
        $client = Client::factory()->create(['document' => '123.456.789-00', 'notes' => 'Renda informal, sem conta bancária']);
        $appointment = Appointment::factory()->for($client)->create(['notes' => 'Trazer extrato do INSS']);

        $row = DB::table('clients')->find($client->id);
        $this->assertStringNotContainsString('123.456.789', $row->document);
        $this->assertStringNotContainsString('12345678900', $row->document);
        $this->assertStringNotContainsString('Renda', $row->notes);
        $this->assertStringNotContainsString('INSS', DB::table('appointments')->find($appointment->id)->notes);

        $this->assertSame('123.456.789-00', $client->fresh()->document);
    }

    public function test_an_encrypted_document_is_still_found_by_its_full_number(): void
    {
        $this->actingAs(User::factory()->create());
        Client::factory()->create(['name' => 'Ana Paula', 'document' => '123.456.789-00']);

        Livewire::test(ClientIndex::class)
            ->set('search', '12345678900')->assertSee('Ana Paula')
            ->set('search', '123.456.789-00')->assertSee('Ana Paula')
            ->set('search', '123456')->assertDontSee('Ana Paula');
    }

    public function test_the_agenda_feed_is_rate_limited(): void
    {
        $this->actingAs(User::factory()->create());
        $url = '/agenda/eventos?start=2026-10-05T00:00:00Z&end=2026-10-12T00:00:00Z';

        foreach (range(1, 120) as $request) {
            $this->getJson($url)->assertOk();
        }

        $this->getJson($url)->assertTooManyRequests();
    }
}
