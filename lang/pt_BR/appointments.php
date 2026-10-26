<?php

declare(strict_types=1);

return [
    'status' => [
        'scheduled' => 'Agendado',
        'confirmed' => 'Confirmado',
        'in_progress' => 'Em atendimento',
        'completed' => 'Concluído',
        'cancelled' => 'Cancelado',
        'no_show' => 'Não compareceu',
    ],

    'transition' => [
        'scheduled' => 'Voltar para agendado',
        'confirmed' => 'Confirmar presença',
        'in_progress' => 'Iniciar atendimento',
        'completed' => 'Concluir',
        'cancelled' => 'Cancelar atendimento',
        'no_show' => 'Não compareceu',
    ],

    'activity' => [
        'created' => 'Atendimento criado',
        'rescheduled' => 'Horário alterado',
        'status_changed' => 'Status alterado',
        'responsible_changed' => 'Responsável alterado',
        'cancelled' => 'Atendimento cancelado',
    ],

    'location_type' => [
        'on_campus' => 'Na universidade',
        'external' => 'Externo',
        'remote' => 'On-line',
    ],

    'errors' => [
        'invalid_period' => 'O horário final precisa ser depois do horário inicial.',
        'too_long' => 'Um atendimento pode durar no máximo :hours horas.',
        'conflict' => ':name já tem atendimento das :start às :end. Escolha outro horário ou outro responsável.',
        'inactive_service' => 'Este serviço foi desativado e não pode ser usado em novos atendimentos.',
        'details_required' => 'Descreva a demanda para o serviço ":service".',
        'responsible_unavailable' => ':name não pode receber atendimentos: a conta está desativada ou é somente de visualização.',
        'transition_not_allowed' => 'Um atendimento ":from" não pode passar para ":to".',
        'not_editable' => 'Só é possível alterar horário ou responsável de atendimentos agendados ou confirmados.',
        'undo_unavailable' => 'Não é mais possível desfazer: o atendimento mudou depois disso. Atualize os dados.',
        'stale' => 'Este atendimento foi alterado por outra pessoa. Atualize os dados e tente de novo.',
        'slot_taken' => 'Este horário acabou de ser ocupado. Atualize os dados e escolha outro horário.',
    ],

    'flash' => [
        'scheduled' => 'Atendimento agendado.',
        'rescheduled' => 'Horário e responsável atualizados.',
        'status_changed' => 'Status alterado para ":status".',
    ],

    'create' => [
        'title' => 'Novo atendimento',
        'subtitle' => 'Escolha o atendido, o serviço e o horário. O checklist de documentos é preparado automaticamente.',
        'step_client' => 'Quem será atendido?',
        'step_service' => 'Qual a demanda?',
        'step_when' => 'Quando e com quem?',
        'step_details' => 'Detalhes',
        'search_placeholder' => 'Buscar por nome, telefone, e-mail ou CPF/CNPJ',
        'search_hint' => 'Digite pelo menos 2 letras.',
        'no_results' => 'Ninguém encontrado com ":term".',
        'create_client' => 'Cadastrar novo atendido',
        'change_client' => 'Trocar',
        'choose_service' => 'Selecione um serviço',
        'checklist_preview' => 'O atendido deverá trazer:',
        'checklist_empty' => 'Este serviço não tem lista de documentos.',
        'details_placeholder' => 'Descreva a demanda em poucas palavras',
        'location_placeholder' => 'Ex.: Sala 205, prédio Azul',
        'notes_placeholder' => 'Algo que a equipe precisa saber antes do atendimento',
        'submit' => 'Agendar atendimento',
        'cancel' => 'Voltar para a agenda',
    ],

    'show' => [
        'when' => 'Quando',
        'responsible' => 'Responsável',
        'where' => 'Onde',
        'notes' => 'Observações',
        'documents' => 'Documentos',
        'documents_progress' => ':received de :total entregues',
        'documents_empty' => 'Nenhum documento solicitado para este serviço.',
        'history' => 'Histórico',
        'edit_schedule' => 'Alterar horário ou responsável',
        'save_schedule' => 'Salvar alteração',
        'cancel_edit' => 'Desistir',
        'confirm_cancel' => 'Cancelar este atendimento? O registro continua no histórico.',
        'confirm_no_show' => 'Marcar que o atendido não compareceu?',
        'client_page' => 'Ver ficha do atendido',
        'by' => 'por :name',
        'by_client' => 'pelo atendido, no link do lembrete',
    ],

    'row' => [
        'details_of' => 'Detalhes do atendimento de :name',
        'contact' => 'Contato',
        'open' => 'Abrir atendimento',
        'received' => 'entregue',
        'pending' => 'pendente',
        'received_label' => 'Entregue',
        'pending_label' => 'Pendente',
    ],

    'fields' => [
        'client' => 'Atendido',
        'service' => 'Serviço',
        'service_details' => 'Descrição da demanda',
        'date' => 'Data',
        'start_time' => 'Início',
        'end_time' => 'Fim',
        'responsible' => 'Responsável',
        'location_type' => 'Tipo de local',
        'location' => 'Local',
        'notes' => 'Observações',
    ],
];
