# Implantação na AWS

Como colocar a CadernetaSEF no ar em uma EC2 com Nginx e PHP-FPM, usando o
PostgreSQL do Amazon RDS. Os arquivos citados ficam em [`deploy/aws/`](../deploy/aws).
Operação do dia a dia: [operations.md](operations.md). Banco: [database-production.md](database-production.md).

## Arquitetura

```
Navegador (web)  ─┐
App Android/iOS  ─┼─ HTTPS 443 ─▶ Nginx ─▶ PHP-FPM ─▶ Laravel ─ TLS verify-full, 5432 ─▶ RDS PostgreSQL
PWA em /app/     ─┘                │                    ▲         (VPC privada, sem acesso público)
                                   └ fila e agendador ──┘
```

- Web e app usam a mesma aplicação Laravel, as mesmas regras de domínio e o mesmo
  banco. O app nunca fala com o PostgreSQL; ele chama `/api/v1` com um token.
- O banco aceita conexões só do security group da EC2. A aplicação entra como
  `agenda_app`, que não altera estrutura nem apaga histórico.
- Fila (`caderneta-queue.service`) e agendador (`caderneta-scheduler.timer`) são
  serviços do systemd na mesma EC2. Não há Redis, NAT Gateway, load balancer nem
  RDS Proxy: nada disso é necessário para o volume do projeto.

## O que existe hoje na AWS

Conferido no console em 22/10/2026, em `sa-east-1`:

| Recurso | Situação |
| --- | --- |
| EC2 `caderneta-sef-web` | t3.small, Ubuntu 26.04 (não 24.04), em execução, IP público automático, IMDSv2 obrigatório, sem IAM role |
| RDS `caderneta-sef-db` | PostgreSQL, db.t4g.micro, Single-AZ, `Publicly accessible = No`, banco `caderneta_sef`, usuário mestre `postgres`, porta 5432, CA `rds-ca-rsa2048-g1` |
| Rede | EC2 e RDS na mesma VPC; o RDS aceita 5432 do security group da EC2 (`rds-ec2-1` ← `ec2-rds-1`) |

Os scripts funcionam em Ubuntu 24.04 ou mais novo: a versão do PHP é detectada,
e não fixada.

Pendências de infraestrutura, que dependem de quem administra a conta:

1. Domínio. É preciso um nome (por exemplo, um subdomínio da Feevale) com
   registro A apontando para a EC2. O Let's Encrypt não emite certificado para os
   nomes `*.amazonaws.com`, e o app e o PWA exigem HTTPS.
2. IP fixo. O IP público automático muda quando a instância é parada e
   iniciada, e o domínio deixaria de apontar para ela. Um Elastic IP associado
   resolve. Ele tem o mesmo preço por hora do IP público que a instância já usa,
   mas é um recurso novo: crie só com a aprovação de quem paga a conta.
3. Security group da EC2. Entrada 80 e 443 de `0.0.0.0/0` e `::/0`. SSH
   (22) só de onde a equipe administra (veja "Acesso à instância").
4. Limpeza. Remova do security group `cadernetasef-db-access` a regra de
   entrada `177.174.194.242/32`. Com o banco privado ela não tem efeito, mas
   deixaria o banco exposto a esse IP se alguém um dia ligasse o acesso público.
5. Proteções. Ative *Termination protection* na EC2 e *Deletion protection*
   no RDS (Modify → sem custo). Em *Maintenance & backups*, confira se a
   retenção de backups automáticos é de pelo menos 7 dias.

## Custos

Nada foi criado na AWS por este trabalho. Pontos de atenção:

- o IPv4 público da EC2 é cobrado por hora, com ou sem Elastic IP;
- snapshots manuais acima da cota gratuita de backup são cobrados por GB/mês;
- Amazon SES (se escolhido para os e-mails) cobra por mensagem e exige
  verificação do domínio. Qualquer serviço de e-mail precisa de aprovação antes.

## Acesso à instância

O banco só é alcançável de dentro da VPC, então os comandos de banco rodam na EC2.
Formas de entrar, da mais simples à mais segura:

- SSH com a chave da instância, com a regra de entrada 22 restrita à sua faixa
  de IP. Em internet móvel o IP muda; use a faixa da operadora apenas enquanto
  trabalha e remova a regra depois.
- EC2 Instance Connect pelo console, com a regra 22 vinda da prefix list
  `com.amazonaws.sa-east-1.ec2-instance-connect`.
- Systems Manager Session Manager, sem porta aberta: anexe à EC2 uma IAM role
  com a política `AmazonSSMManagedInstanceCore` (sem custo) e use
  `aws ssm start-session` com a AWS CLI e o `session-manager-plugin`. Também
  permite túnel para o RDS (`AWS-StartPortForwardingSessionToRemoteHost`).

Nunca torne o RDS público para acessá-lo de fora.

## Primeira implantação

Na EC2, com o domínio já apontando para ela:

```sh
sudo apt-get update && sudo apt-get install -y git
git clone https://github.com/holid4y/CadernetaSEF.git ~/CadernetaSEF
cd ~/CadernetaSEF
sudo deploy/aws/bin/provision.sh agenda.exemplo.org
```

O `provision.sh` pode rodar de novo sempre que os arquivos de `deploy/aws` mudarem. Ele:

- instala Nginx, PHP-FPM, Composer, Node, o cliente PostgreSQL e o certbot
  (só pacotes do Ubuntu);
- exige PHP 8.3+, Node 20.19+ e psql 15+. Se o Node do Ubuntu for antigo, ele
  para e indica a instalação pelo NodeSource;
- cria 2 GiB de swap, o usuário `caderneta` e `/var/www/caderneta-sef`;
- cria `shared/.env` a partir de [`env.production.example`](../deploy/aws/env.production.example),
  com uma `APP_KEY` nova. Guarde essa chave no cofre de senhas do projeto:
  sem ela, CPF/CNPJ, observações e links de formulários ficam ilegíveis;
- baixa o pacote de certificados do RDS para `/etc/ssl/certs/rds-global-bundle.pem`;
- configura o pool do PHP-FPM, o Nginx com certificado do Let's Encrypt
  (renovação automática), a fila, o agendador e os comandos `caderneta-*`.

Depois, prepare o banco (detalhes em [database-production.md](database-production.md)):

```sh
sudo caderneta-database-setup --host caderneta-sef-db.XXXXXXXX.sa-east-1.rds.amazonaws.com
```

O script pede a senha do usuário mestre sem mostrá-la e cria `agenda_owner`,
`agenda_app` e `agenda_reports` com senhas geradas na hora. A senha do
`agenda_app` vai para o `.env`; a do dono, para `shared/db-owner.env` (só o
usuário `caderneta` lê). Nenhuma senha aparece na tela.

Revise o `.env` (`sudo -u caderneta nano /var/www/caderneta-sef/shared/.env`):
`APP_URL`, `MAIL_*` e `FORMS_*`. Então faça o primeiro deploy:

```sh
sudo -u caderneta caderneta-deploy --ref main --migrate --with-pwa
sudo -u caderneta php /var/www/caderneta-sef/current/artisan users:create coordenacao@feevale.br "Nome da Coordenação" --role=admin
```

O deploy lista as migrations pendentes e só continua quando você digita
`MIGRATE`. Em seguida, ele:

- roda as migrations como `agenda_owner`;
- cria o catálogo de serviços;
- aplica `privileges.sql` e confere tudo com `verify.sql`;
- monta os caches e troca a versão;
- testa `https://<domínio>/up`.

`--with-pwa` publica o app em `https://<domínio>/app/`, no modo servidor.

### Conferência final

- `https://<domínio>/up` responde 200, e `http://` redireciona para `https://`;
- `sudo -u caderneta caderneta-check-db` termina sem `FAIL`;
- o login web funciona com a conta criada; o login com senha errada é recusado;
- `systemctl status caderneta-queue caderneta-scheduler.timer` mostra os dois ativos;
- um atendimento criado no web aparece no app (`/app/`, menu do avatar →
  "Atualizar dados") e vice-versa.

## Versões seguintes

```sh
sudo -u caderneta caderneta-deploy --ref main          # só código
sudo -u caderneta caderneta-deploy --ref main --migrate  # com migrations, após um snapshot do RDS
```

Cada deploy é uma pasta nova em `releases/`. A troca é atômica (symlink
`current`), e o site só fica em manutenção durante migrations. Se `/up` não
responder, a versão anterior volta sozinha. Rollback manual e demais cuidados:
[operations.md](operations.md).

## Apps Android e iOS

Os projetos nativos usam o mesmo código do PWA.

### Android (APK)

É preciso JDK 21 (o Capacitor 8 compila em Java 21) e o Android SDK com a
plataforma 36 e os build-tools 36. Sem o Android Studio, os dois podem ficar em
`~/Android` (`jdk-21` e `Sdk`).

```sh
cd mobile
npm ci
MOBILE_API_URL=https://agenda.exemplo.org/api/v1 npm run sync:server
cd android
JAVA_HOME=~/Android/jdk-21 ANDROID_HOME=~/Android/Sdk ./gradlew assembleRelease
BT=~/Android/Sdk/build-tools/36.1.0
$BT/zipalign -P 16 -f 4 app/build/outputs/apk/release/app-release-unsigned.apk /tmp/alinhado.apk
$BT/apksigner sign --ks ~/Android/keys/caderneta-sef-release.jks --ks-key-alias caderneta-sef \
    --ks-pass file:$HOME/Android/keys/caderneta-sef-release.password --out CadernetaSEF.apk /tmp/alinhado.apk
$BT/apksigner verify --print-certs CadernetaSEF.apk
```

A chave de assinatura (`.jks` e a senha) fica fora do repositório. **Guarde uma
cópia dela no cofre de senhas do projeto**: toda atualização do app precisa ser
assinada com a mesma chave, senão os aparelhos não aceitam a nova versão. Para
instalar o APK direto (sem loja), o aparelho precisa permitir apps de fontes
desconhecidas. A Play Store exige uma conta de desenvolvedor Google.

Antes de cada nova versão, aumente `versionCode` (e `versionName`) em
`mobile/android/app/build.gradle`.

### iPhone

Um app iOS instalável exige macOS com Xcode e uma conta Apple Developer. Sem
ela, o iPhone usa o PWA, que é o mesmo app: no Safari, abra
`https://<domínio>/app/` → Compartilhar → "Adicionar à Tela de Início". Ele abre
em tela cheia, com ícone próprio, e fala com a mesma API.

A API aceita chamadas das origens `https://localhost` (Android) e
`capacitor://localhost` (iOS), configuradas em `CORS_ALLOWED_ORIGINS`. Detalhes
do contrato: [mobile-integration.md](mobile-integration.md).

## No servidor

```
/var/www/caderneta-sef/
├── current -> releases/20261023120000   versão no ar
├── releases/                            últimas 5 versões
└── shared/
    ├── .env              640 caderneta:www-data
    ├── db-owner.env      600 caderneta (senha das migrations)
    ├── db-reports.env    600 root
    └── storage/          logs e views compiladas
/var/backups/caderneta-sef/              dumps de caderneta-backup (700)
/etc/nginx/sites-available/caderneta-sef.conf
/etc/php/<versão>/fpm/pool.d/caderneta-sef.conf
/etc/systemd/system/caderneta-queue.service, caderneta-scheduler.{service,timer}
/usr/local/bin/caderneta-{deploy,rollback,check-db,backup,database-setup}
```
