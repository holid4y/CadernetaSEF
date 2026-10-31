<?php

return [
    'title' => 'Google Agenda',
    'continue' => 'Continuar com o Google',
    'open' => 'Abrir no Google Agenda',
    'calendar_description' => 'Atendimentos da Caderneta SEF em que você é responsável. O sistema mantém esta agenda atualizada; mudanças feitas aqui não voltam para a Caderneta.',
    'event_description' => 'Atendimento da Caderneta SEF. Detalhes e documentos: :url',
    'pending' => 'A completar',
    'from_google' => 'Do Google Agenda',
    'untitled' => 'Evento sem título',

    'flash' => [
        'connected' => 'Google Agenda conectada à conta :email.',
        'disconnected' => 'A conta Google foi desconectada.',
        'source_saved' => 'Os eventos de “:name” vão aparecer na agenda em alguns minutos.',
        'source_cleared' => 'Nenhuma agenda do Google está sendo importada.',
        'dismissed' => 'O evento não vai mais aparecer na agenda.',
    ],

    'errors' => [
        'not_configured' => 'A integração com o Google ainda não foi configurada no servidor.',
        'permissions' => 'Para conectar, marque todas as permissões pedidas pelo Google.',
        'unreachable' => 'Não foi possível falar com o Google agora. Tente de novo em alguns minutos.',
        'refused' => 'O Google recusou a conexão. Tente de novo.',
        'expired' => 'O pedido de conexão expirou. Comece de novo.',
        'denied' => 'A conexão foi cancelada no Google.',
        'link' => 'Este link de conexão já foi usado ou expirou. Peça outro no app.',
        'calendar' => 'Escolha uma das suas agendas do Google.',
        'calendars_unavailable' => 'Não foi possível carregar suas agendas do Google agora.',
        'already_imported' => 'Este evento já virou um atendimento.',
        'removed' => 'Este evento foi apagado no Google Agenda.',
    ],
];
