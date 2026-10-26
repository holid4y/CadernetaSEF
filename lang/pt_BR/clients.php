<?php

declare(strict_types=1);

return [
    'type' => [
        'individual' => 'Pessoa Física',
        'organization' => 'Pessoa Jurídica',
    ],

    'fields' => [
        'type' => 'Tipo',
        'name_individual' => 'Nome completo',
        'name_organization' => 'Razão social',
        'trade_name' => 'Nome fantasia',
        'document' => 'CPF ou CNPJ',
        'phone' => 'Telefone / WhatsApp',
        'email' => 'E-mail',
        'notes' => 'Observações',
        'accepts_reminders' => 'Aceita receber lembretes dos atendimentos',
        'accepts_reminders_hint' => 'Só marque com a autorização do atendido (LGPD).',
        'active' => 'Cadastro ativo',
    ],

    'errors' => [
        'contact_required' => 'Informe um telefone ou um e-mail para contato.',
        'invalid_individual_document' => 'CPF inválido. Confira os 11 dígitos.',
        'invalid_organization_document' => 'CNPJ inválido. Confira os 14 dígitos.',
        'document_taken' => 'Já existe um atendido com este CPF/CNPJ. Busque pelo número completo na lista de atendidos.',
        'invalid_phone' => 'Telefone inválido. Informe o DDD e o número.',
        'stale' => 'Este cadastro foi alterado por outra pessoa. Atualize os dados e tente de novo.',
    ],

    'flash' => [
        'created' => 'Atendido cadastrado.',
        'updated' => 'Cadastro atualizado.',
    ],

    'index' => [
        'title' => 'Atendidos',
        'subtitle' => 'Pessoas, MEIs, empresas e entidades atendidas pelo projeto.',
        'search' => 'Buscar por nome, telefone, e-mail ou CPF/CNPJ',
        'all_types' => 'Todos',
        'new' => 'Novo atendido',
        'empty' => 'Nenhum atendido encontrado.',
        'appointments_count' => '{0} Nenhum atendimento|{1} 1 atendimento|[2,*] :count atendimentos',
        'inactive' => 'Inativo',
        'details_of' => 'Detalhes de :name',
        'next_appointment' => 'Próximo atendimento',
        'recent_appointments' => 'Últimos atendimentos',
        'open' => 'Abrir ficha completa',
        'previous' => 'Anterior',
        'next' => 'Próxima',
    ],

    'show' => [
        'schedule' => 'Agendar atendimento',
        'edit' => 'Editar cadastro',
        'upcoming' => 'Próximos atendimentos',
        'past' => 'Histórico',
        'upcoming_empty' => 'Nenhum atendimento agendado.',
        'past_empty' => 'Ainda não houve atendimentos.',
        'contact' => 'Contato',
        'reminders_on' => 'Recebe lembretes',
        'reminders_off' => 'Não recebe lembretes',
    ],

    'create_title' => 'Novo atendido',
    'edit_title' => 'Editar atendido',
    'save' => 'Salvar',
    'cancel' => 'Cancelar',
];
