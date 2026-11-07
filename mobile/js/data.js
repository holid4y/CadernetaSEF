export const PROJECT = {
    kicker: 'Sustentabilidade',
    name: 'Econômica e Financeira',
    title: 'Agenda de atendimentos',
    place: 'Sala 105, Campus II da Universidade Feevale, ou on-line',
    coordinator: 'Profa. Maristela Mercedes Bauer',
    // From the paper agenda sheet. Confirm the area code.
    email: 'projetosustentabilidade@feevale.br',
    phone: '(51) 99125-1538',
};

export const ROLES = {
    admin: 'Administrador',
    team: 'Membro da equipe',
    viewer: 'Visualizador',
};

export const STATUS = {
    scheduled: { label: 'Agendado', tone: 'primary', action: 'Voltar para agendado' },
    confirmed: { label: 'Confirmado', tone: 'moss', action: 'Confirmar presença' },
    in_progress: { label: 'Em atendimento', tone: 'ochre', action: 'Iniciar atendimento' },
    completed: { label: 'Concluído', tone: 'bark', action: 'Concluir atendimento' },
    cancelled: { label: 'Cancelado', tone: 'rose', action: 'Cancelar atendimento' },
    no_show: { label: 'Não compareceu', tone: 'clay', action: 'Não compareceu' },
};

export const STATUS_ORDER = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];

export const TRANSITIONS = {
    scheduled: ['confirmed', 'in_progress', 'cancelled', 'no_show'],
    confirmed: ['in_progress', 'cancelled', 'no_show'],
    in_progress: ['completed', 'cancelled'],
    completed: [],
    cancelled: [],
    no_show: [],
};

export const BLOCKING = ['scheduled', 'confirmed', 'in_progress'];

// Where the project sees people unless the booking says otherwise.
export const DEFAULT_PLACE = 'Sala 105, Campus II';

export const LOCATION_TYPES = {
    on_campus: 'Na universidade',
    external: 'Externo',
    remote: 'On-line',
};

export const CLIENT_TYPES = {
    individual: 'Pessoa física',
    organization: 'Pessoa jurídica',
    ngo: 'ONG',
};

// Companies and NGOs are registered by CNPJ and corporate name.
export const isLegalEntity = (type) => type === 'organization' || type === 'ngo';

export const MAX_APPOINTMENT_HOURS = 8;

const IRPF_DOCUMENTS = [
    'Documento de identificação com CPF',
    'Comprovante de residência atualizado',
    'Recibo e cópia da última declaração entregue',
    'Informes de rendimentos (empregador, bancos, INSS)',
    'Comprovantes de despesas médicas e odontológicas',
    'Comprovantes de despesas com educação',
    'Dados bancários para restituição',
];

export const CATALOG = [
    {
        slug: 'fiscal',
        name: 'Fiscal',
        hint: 'IRPF, MEI e guias de tributos',
        services: [
            { slug: 'declaracao-de-imposto-de-renda', name: 'Declaração de imposto de renda', minutes: 60, documents: IRPF_DOCUMENTS },
            { slug: 'irpf', name: 'IRPF', minutes: 60, documents: IRPF_DOCUMENTS },
            {
                slug: 'atendimento-mei',
                name: 'Atendimento MEI',
                minutes: 60,
                documents: [
                    'CNPJ ou Certificado da Condição de MEI (CCMEI)',
                    'Login e senha da conta gov.br (nível prata ou ouro)',
                    'Relatório mensal de receitas brutas',
                    'Comprovantes das guias DAS pagas',
                ],
            },
            {
                slug: 'emissao-de-guias-para-pagamento-de-tributos',
                name: 'Emissão de guias para pagamento de tributos',
                minutes: 30,
                documents: ['CPF ou CNPJ do contribuinte', 'Login e senha da conta gov.br', 'Período de apuração da guia'],
            },
            { slug: 'atendimento-externo', name: 'Atendimento externo', minutes: 180, documents: [] },
        ],
    },
    {
        slug: 'financas-pessoais',
        name: 'Finanças Pessoais',
        hint: 'Orçamento e fluxo de caixa da família',
        services: [
            {
                slug: 'orcamento-familiar-e-pessoal',
                name: 'Orçamento familiar e pessoal',
                minutes: 60,
                documents: [
                    'Extratos bancários dos últimos 3 meses',
                    'Faturas de cartão de crédito dos últimos 3 meses',
                    'Lista de receitas e despesas fixas da família',
                ],
            },
            {
                slug: 'fluxo-de-caixa',
                name: 'Fluxo de caixa',
                minutes: 60,
                documents: ['Extratos bancários dos últimos 3 meses', 'Lista de entradas e saídas previstas'],
            },
            { slug: 'planejamento-financeiro', name: 'Planejamento financeiro', minutes: 60, documents: [] },
        ],
    },
    {
        slug: 'empresarial',
        name: 'Empresarial',
        hint: 'Preço, custos e orçamento da empresa',
        services: [
            {
                slug: 'formacao-do-preco-de-venda',
                name: 'Formação do preço de venda',
                minutes: 60,
                documents: ['Notas fiscais de compra de insumos', 'Lista de custos fixos mensais', 'Preços praticados atualmente'],
            },
            {
                slug: 'calculo-de-custos',
                name: 'Cálculo de custos',
                minutes: 60,
                documents: ['Notas fiscais de compra de insumos', 'Lista de custos fixos mensais', 'Folha de pagamento ou pró-labore'],
            },
            { slug: 'orcamento-empresarial', name: 'Orçamento empresarial', minutes: 60, documents: [] },
            { slug: 'mapeamento-de-processos', name: 'Mapeamento de processos', minutes: 90, documents: [] },
        ],
    },
    {
        slug: 'terceiro-setor',
        name: 'Terceiro Setor',
        hint: 'ONGs e entidades sem fins lucrativos',
        services: [
            {
                slug: 'prestacao-de-contas-para-entidades-do-terceiro-setor',
                name: 'Prestação de contas para entidades do terceiro setor',
                minutes: 90,
                documents: [
                    'Estatuto social',
                    'Ata de eleição da diretoria atual',
                    'Extratos bancários do período',
                    'Notas fiscais e recibos das despesas',
                    'Termos de convênio ou parceria, quando houver',
                ],
            },
            { slug: 'elaboracao-de-demonstracoes-contabeis', name: 'Elaboração de demonstrações contábeis', minutes: 90, documents: [] },
            { slug: 'elaboracao-de-relatorio-de-responsabilidade-social', name: 'Elaboração de relatório de responsabilidade social', minutes: 60, documents: [] },
        ],
    },
    {
        slug: 'outros',
        name: 'Outros',
        hint: 'Descreva a demanda em poucas palavras',
        services: [{ slug: 'outros', name: 'Outros', minutes: 60, documents: [], requiresDetails: true }],
    },
];

export const MEI_SERVICE = 'atendimento-mei';
