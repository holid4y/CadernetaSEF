<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Domain\Clients\Actions\SaveClient;
use App\Domain\Clients\Enums\ClientType;
use App\Models\Client;
use Livewire\Form;

class ClientForm extends Form
{
    public ?Client $client = null;

    public string $type = ClientType::Individual->value;

    public string $name = '';

    public string $trade_name = '';

    public string $document = '';

    public string $phone = '';

    public string $email = '';

    public string $notes = '';

    public bool $accepts_reminders = false;

    public bool $active = true;

    public function isOrganization(): bool
    {
        return $this->type === ClientType::Organization->value;
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;
        $this->type = $client->type->value;
        $this->name = $client->name;
        $this->trade_name = (string) $client->trade_name;
        $this->document = (string) $client->document;
        $this->phone = (string) $client->phone;
        $this->email = (string) $client->email;
        $this->notes = (string) $client->notes;
        $this->accepts_reminders = $client->accepts_reminders;
        $this->active = $client->active;
    }

    protected function rules(): array
    {
        return SaveClient::rules($this->client, $this->type);
    }

    protected function messages(): array
    {
        return SaveClient::messages();
    }

    public function save(): Client
    {
        $this->validate();

        return app(SaveClient::class)->handle($this->client, [
            'type' => $this->type,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'document' => $this->document,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
            'accepts_reminders' => $this->accepts_reminders,
            'active' => $this->active,
        ]);
    }
}
