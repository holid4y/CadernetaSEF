# Caderneta SEF

A Caderneta é a agenda do projeto de extensão Sustentabilidade Econômica e
Financeira (SEF), do curso de Ciências Contábeis da Feevale, coordenado pela
Profa. Maristela Mercedes Bauer. O projeto orienta pessoas físicas, MEIs,
pequenas empresas e ONGs do Vale do Sinos em questões financeiras, fiscais e
contábeis.

Quem fez fomos nós, o grupo Os Três Mosqueteiros. Entramos em contato com três
projetos de extensão da universidade e acabamos escolhendo o SEF. Na primeira
conversa presencial, em setembro, apareceu o problema: os atendimentos estavam
espalhados entre Drive, Docs e Excel. A ideia era fazer uma agenda digital com
calendário, e só. A cada conversa com a coordenação surgia um pedido novo, e o
sistema foi crescendo bem além disso.

Hoje ela está no ar em [cadernetasef.com.br](https://cadernetasef.com.br).

![Agenda da semana](docs/screenshots/agenda.png)

## O que dá para fazer

A equipe cadastra quem vai ser atendido (pessoa física, jurídica ou ONG) e marca
o horário com alguém do grupo. As planilhas que o projeto já tinha podem ser
importadas direto. Cada atendimento tem a lista do que a pessoa precisa trazer,
que é conferida no dia, e cada responsável pode acompanhar até cinco atendimentos
ao mesmo tempo.

Quem tem e-mail cadastrado recebe uma confirmação quando o horário é marcado ou
muda, e um lembrete alguns dias antes, com um link para confirmar presença ou
cancelar. Se a pessoa não quiser receber, é só desmarcar na ficha dela.

Quem da equipe conecta a conta Google vê os próprios atendimentos na Google
Agenda, e o que foi marcado lá aparece na Caderneta para ser completado. A parte
de Impacto junta os números que o projeto usa nos relatórios, separa por tipo de
atendido e exporta em CSV.

Se quiser só dar uma olhada, tem a
[prévia da web](https://holid4y.github.io/CadernetaSEF/) e a
[do app](https://holid4y.github.io/CadernetaSEF/app/), as duas com dados
inventados. O que você mudar lá não fica salvo.

## No que roda

A base é a que escolhemos no começo: Laravel, Livewire, Tailwind, Alpine.js e
FullCalendar, com PostgreSQL no banco. Algumas regras ficam no próprio banco,
como não repetir CPF/CNPJ entre atendidos ativos e não deixar reescrever o
histórico dos atendimentos. CPF/CNPJ e observações ficam criptografados, e quem
entra como visualizador não vê esses dados.

O app veio depois e fica em [`mobile/`](mobile/README.md). No Android ele é
instalado pelo APK que sai em
[Releases](https://github.com/holid4y/CadernetaSEF/releases), e no iPhone é só
abrir [cadernetasef.com.br/app](https://cadernetasef.com.br/app/) no Safari. Ele
usa a mesma API e as mesmas regras da web.

Em produção, o sistema roda numa EC2 da AWS com Nginx e PHP-FPM, o banco fica no
RDS, e o domínio é registrado no Registro.br com DNS na Cloudflare.

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

A CI roda tudo isso em cada push na `main` e em cada pull request.

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
- [Google Agenda](docs/google-calendar.md)
- [Integração com formulários](docs/forms-integration.md)

## O grupo

Somos Os Três Mosqueteiros. A Beatriz Carvalho é a Product Owner e a ponte entre
o cliente e quem programa: conversa com a coordenação, vê o que dá para fazer e
cuida de como as telas ficam. Gustavo Bulegon programa junto com o Matheus e ajuda
a Beatriz no contato com o cliente. O Matheus Almada estrutura e desenvolve o
sistema em PHP, Laravel e SQL.
