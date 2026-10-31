<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalForm extends Model
{
    protected function casts(): array
    {
        return [
            'source_version' => 'integer',
            'source_updated_at' => 'immutable_datetime',
            'url' => 'encrypted',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function actionLabel(): string
    {
        return $this->status === 'completed' ? 'Ver formulário feito' : 'Preencher formulário';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pendente',
            'in_progress' => 'Em preenchimento',
            'completed' => 'Concluído',
            'deleted' => 'Removido na plataforma de formulários',
            default => 'Indisponível',
        };
    }
}
