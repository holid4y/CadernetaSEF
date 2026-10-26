# Caderneta SEF

Agenda dos atendimentos do projeto de extensão Sustentabilidade Econômica e
Financeira (SEF), do curso de Ciências Contábeis da Feevale, coordenado pela
Profa. Maristela Mercedes Bauer. O projeto ajuda pessoas físicas, MEIs,
pequenas empresas e ONGs do Vale do Sinos com imposto de renda, guias, fluxo de
caixa e prestação de contas.

Pelo sistema a equipe cadastra quem vai ser atendido, marca o horário com
alguém do grupo e acompanha o atendimento até o fim, junto com a lista de
documentos que a pessoa precisa trazer. Se o atendido autorizar, ele recebe um
lembrete por e-mail um dia antes.

Dá para ver funcionando sem instalar nada: tem a
[prévia da web](https://holid4y.github.io/CadernetaSEF/) e a
[do app](https://holid4y.github.io/CadernetaSEF/app/), as duas com dados
inventados. O que você mudar lá não fica salvo.

![Agenda da semana](docs/screenshots/agenda.png)

## No que roda

A web é feita em Laravel 13 com Livewire 4 e Tailwind 4, e a agenda usa o
FullCalendar. O banco é PostgreSQL 18, e algumas regras ficam nele mesmo, como
impedir que a mesma pessoa tenha dois atendimentos no mesmo horário. CPF/CNPJ e
observações ficam criptografados, e quem entra como visualizador não vê esses
dados.

O app fica em [`mobile/`](mobile/README.md). No Android ele é instalado pelo APK
que sai em [Releases](https://github.com/holid4y/CadernetaSEF/releases), e no
iPhone ele é aberto pelo Safari, como PWA. Ele usa a mesma API e as mesmas
regras da web.

Em produção, o sistema roda numa EC2 da AWS com Nginx e PHP-FPM, e o banco fica
no RDS.

## Rodando na sua máquina

Você vai precisar de PHP 8.3 ou mais novo (com `pdo_pgsql`, `mbstring`, `intl`,
`bcmath`, `gd` e `zip`), Composer, Node 22 e PostgreSQL 18.

```sh
sudo -u postgres psql -c "CREATE ROLE agenda LOGIN PASSWORD 'agenda' CREATEDB;"
sudo -u postgres createdb -O agenda agenda_contabil
sudo -u postgres createdb -O agenda agenda_contabil_test

composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
composer dev
```

O sistema abre em `http://localhost:8000`. O seed cria algumas semanas de
atendimentos fictícios e três contas, todas com a senha `password`:
`admin@agenda.local`, `equipe@agenda.local` e `visualizador@agenda.local`. Na
sua máquina nenhum e-mail sai de verdade, eles vão para
`storage/logs/laravel.log`.

## Testes

```sh
php artisan test
vendor/bin/pint --test
cd mobile && npm test && npm run test:layout
```

Os testes rodam num PostgreSQL de verdade, no banco `agenda_contabil_test`,
porque parte das regras está no banco. Um deles cria um banco temporário, por
isso o usuário `agenda` precisa de `CREATEDB`. O `test:layout` abre o app no
navegador pelo Playwright.

A CI roda tudo isso em cada pull request, e a `main` só aceita merge com ela
passando.

## Prévia no GitHub Pages

Cada push na `main` roda o `.github/workflows/preview.yml`, que gera a prévia
com dados de demonstração e publica no Pages. Em *Settings → Pages*, a origem
precisa estar em GitHub Actions. Com *Deploy from a branch* a prévia sai do ar.

## Mais detalhes

- [Implantação na AWS](docs/deployment-aws.md)
- [Banco em produção](docs/database-production.md)
- [Operação do dia a dia](docs/operations.md)
- [O que ainda falta para produção](docs/production-readiness.md)
- [API do app](docs/mobile-integration.md)
- [Integração com formulários](docs/forms-integration.md)

## Equipe

A Beatriz é a Product Owner. Ela conversa com o cliente, combina com o grupo o
que entra e desenha as telas. O resto do grupo cuida do back-end, do front-end
e do banco.
