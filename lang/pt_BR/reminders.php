<?php

declare(strict_types=1);

return [
    'subject' => 'Lembrete do seu atendimento em :date',
    'greeting' => 'Olá, :name!',
    'when' => 'Seu atendimento de :service é :weekday, :date, às :time.',
    'where' => 'Local: :place.',
    'documents' => 'Traga, por favor:',
    'respond_prompt' => 'Para confirmar ou cancelar:',
    'respond_action' => 'Confirmar ou cancelar',
    'respond_note' => 'Se não puder comparecer, avise pelo link acima para liberarmos o horário.',
    'salutation' => 'Equipe do projeto Sustentabilidade Econômica e Financeira',
    'command_result' => '{0} Nenhum lembrete enviado.|{1} 1 lembrete enviado.|[2,*] :count lembretes enviados.',

    'page' => [
        'hello' => 'Olá, :name. Seu atendimento está marcado para',
        'service' => 'Serviço',
        'where' => 'Local',
        'status' => 'Situação',
        'confirm' => 'Confirmar presença',
        'cancel' => 'Não poderei ir',
        'cancel_confirm' => 'Cancelar este atendimento? O horário será liberado.',
        'done_confirm' => 'Presença confirmada. Até lá!',
        'done_cancel' => 'Atendimento cancelado. Obrigado por avisar.',
        'unchanged' => 'Este atendimento não pode mais ser alterado por aqui.',
    ],

    'errors' => [
        'disabled' => 'O envio de lembretes por e-mail está desligado neste servidor. Use o WhatsApp ou o e-mail do seu aparelho.',
        'closed' => 'Este atendimento não recebe mais lembretes.',
        'past' => 'O atendimento já começou.',
        'inactive' => 'Este atendido está inativo.',
        'invalid_email' => 'Cadastre um e-mail válido para enviar o lembrete.',
        'mail_unavailable' => 'O servidor de e-mail não aceitou o lembrete agora. Tente novamente em alguns minutos.',
    ],

    'card' => [
        'title' => 'Lembrete',
        'sent_at' => 'Enviado por e-mail em :date.',
        'scheduled' => 'Será enviado por e-mail cerca de 24 horas antes.',
        'no_consent' => 'O atendido não autorizou lembretes.',
        'no_email' => 'Sem e-mail cadastrado; use o WhatsApp.',
        'send_now' => 'Enviar e-mail agora',
        'send_again' => 'Reenviar e-mail',
        'whatsapp' => 'Enviar pelo WhatsApp',
        'sent' => 'Lembrete enviado para :email.',
        'not_sent' => 'Não foi possível enviar o lembrete.',
    ],
];
