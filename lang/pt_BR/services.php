<?php

declare(strict_types=1);

return [
    'title' => 'Serviços',
    'page_title' => 'Configurações',
    'page_subtitle' => 'Lembretes por e-mail e as demandas que a equipe pode agendar, com os documentos de cada uma.',
    'new' => 'Novo serviço',
    'edit' => 'Editar',
    'inactive' => 'Desativado',
    'documents_count' => '{0} Sem checklist|{1} 1 documento|[2,*] :count documentos',
    'empty_hint' => 'Escolha um serviço para editar ou crie um novo.',
    'new_title' => 'Novo serviço',
    'edit_title' => 'Editar serviço',

    'fields' => [
        'category' => 'Categoria',
        'name' => 'Nome',
        'description' => 'Descrição',
        'duration' => 'Duração sugerida (minutos)',
        'requires_details' => 'Pedir descrição da demanda ao agendar',
        'active' => 'Disponível para novos atendimentos',
        'documents' => 'Documentos que o atendido deve trazer',
        'new_document' => 'Novo documento',
    ],

    'documents_hint' => 'Mudanças valem só para novos agendamentos. Atendimentos já marcados mantêm a lista que receberam.',
    'add_document' => 'Adicionar',
    'move_up' => 'Mover :name para cima',
    'move_down' => 'Mover :name para baixo',
    'remove' => 'Remover :name',
    'save' => 'Salvar serviço',
    'cancel' => 'Cancelar',

    'delete' => 'Excluir serviço',
    'confirm_delete' => 'Excluir este serviço do catálogo? Ele some da lista e dos novos agendamentos; os atendimentos antigos continuam com ele no histórico.',

    'errors' => [
        'too_many_documents' => 'Uma lista pode ter no máximo :max documentos.',
        'stale' => 'A lista de documentos mudou. Atualize os dados e tente de novo.',
        'name_taken' => 'Já existe um serviço com esse nome nesta categoria.',
        'has_upcoming' => '{1} Há 1 atendimento marcado com este serviço (:date). Remarque ou cancele antes de excluir.|[2,*] Há :count atendimentos marcados com este serviço, o próximo em :date. Remarque ou cancele antes de excluir.',
    ],

    'flash' => [
        'created' => 'Serviço ":name" criado.',
        'updated' => 'Serviço ":name" atualizado.',
        'deleted' => 'Serviço ":name" excluído do catálogo. Os atendimentos antigos continuam com ele no histórico.',
    ],
];
