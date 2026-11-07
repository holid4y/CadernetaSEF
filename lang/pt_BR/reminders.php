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

    'confirmation' => [
        'subject' => 'Atendimento marcado para :date',
        'subject_moved' => 'Seu atendimento mudou para :date',
        'booked' => 'Seu atendimento no projeto foi marcado.',
        'moved' => 'O horário do seu atendimento mudou. Confira o novo horário:',
        'reminder_ahead' => '{1} Você também vai receber um lembrete 1 dia antes.|[2,*] Você também vai receber um lembrete :count dias antes.',
    ],

    'settings' => [
        'title' => 'Lembretes por e-mail',
        'lead' => 'Quem aceitou receber e-mails recebe a confirmação ao marcar e ao mudar o horário, e um lembrete antes do atendimento.',
        'days' => 'Lembrete quantos dias antes',
        'days_hint' => 'De 1 a :max dias. Vale para os próximos envios.',
        'save' => 'Salvar',
        'saved' => '{1} Lembrete passa a sair 1 dia antes.|[2,*] Lembrete passa a sair :count dias antes.',
        'disabled' => 'O envio de e-mails está desligado neste servidor; a escolha vale quando for ligado.',
    ],

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
        'scheduled' => '{1} Vai por e-mail 1 dia antes; a confirmação sai ao marcar e ao mudar o horário.|[2,*] Vai por e-mail :count dias antes; a confirmação sai ao marcar e ao mudar o horário.',
        'no_consent' => 'O atendido não autorizou lembretes.',
        'no_email' => 'Cadastre um e-mail válido para o lembrete automático, ou use o WhatsApp.',
        'send_now' => 'Enviar e-mail agora',
        'send_again' => 'Reenviar e-mail',
        'sent' => 'Lembrete enviado para :email.',
        'not_sent' => 'Não foi possível enviar o lembrete.',
    ],
];
