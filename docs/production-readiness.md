# Prontidão para produção

Situação em 23/10/2026. "Validado" quer dizer executado e conferido. Quando
diz "simulação", foi num PostgreSQL 18 local montado como o RDS: mestre sem
superusuário, só conexões TLS, certificado conferido com `verify-full`.

Em 23/10/2026, com aprovação, o banco do RDS real foi preparado por um túnel
SSH através da EC2:

- papéis criados, com as senhas geradas e guardadas só na EC2
  (`/root/caderneta-db.env`, root);
- migrations, catálogo de serviços e `privileges.sql` aplicados, e
  `verify.sql` aprovado;
- operações proibidas negadas no próprio RDS;
- conta administradora criada.

A EC2 ainda não tem a aplicação instalada.

## Diagnóstico por área

| Área | Antes deste trabalho | Agora |
| --- | --- | --- |
| Login, sessão, recuperação de senha (web) | implementado e validado | sem mudança de comportamento; o login passou a usar a mesma verificação da API |
| Agenda, atendidos, agendamentos, histórico, reagendamento, cancelamento, checklist | implementado e validado, com uma falha de concorrência na mudança de status | corrigido (lock na linha) e coberto por teste com processos paralelos |
| Catálogo de serviços | implementado e validado | também gerido pelo app (só admin) |
| Painel inicial (web) | parcial: hoje, semana, confirmados, documentos pendentes | sem mudança |
| Painel de impacto, relatórios e CSV (web, M5) | não implementado na web; no app, calculado no aparelho | **pendente** |
| Administração de usuários | sem tela; só `tinker` | comandos `users:create`, `users:deactivate`, `users:password`; tela web **pendente** |
| Lembretes e fila | implementado e validado; sem SMTP de produção | envio manual síncrono e honesto, reconferência no job, interruptor `MAIL_REMINDERS_ENABLED`; envio real **bloqueado** (credenciais e aprovação) |
| Integração de formulários | implementada e validada | preservada; conexão com o parceiro **bloqueada** (depende do outro grupo) |
| API do app | só contrato | implementada e validada |
| App em modo servidor | cliente pronto, sem servidor para testar | validado contra a API real na simulação, com navegador |
| App em modo demo e prévia do Pages | demonstração | sem mudança: continuam fictícios e isolados |
| Banco no RDS | migrations ok localmente; `privileges.sql` com senhas na linha de comando e sem teste em ambiente restrito | preparado e verificado no RDS real (PostgreSQL 18.3), depois de validado na simulação |
| TLS com o banco | `sslmode=prefer`, sem CA | `verify-full` com o pacote do RDS, validado na simulação |
| CORS | padrão do framework: qualquer origem | só as origens dos apps |
| Implantação | inexistente | scripts e configuração em `deploy/aws/`; **não executados** em servidor real |
| Backups | sem procedimento | documentados; dump e restauração ensaiados na simulação |
| Sincronização com Google Agenda (OAuth) | não implementado (fora do escopo) | sem mudança |

## Critérios de aceite

| # | Critério | Status |
| --- | --- | --- |
| 1 | Laravel no PostgreSQL correto, com TLS | validado no RDS real (`caderneta_sef`, TLS 1.3, `verify-full`, migrations rodadas pelo Laravel); falta a aplicação rodando na EC2 |
| 2 | Banco privado | **confirmado** no console (*Publicly accessible = No*, entrada só do SG da EC2) |
| 3 | Migrations aplicadas sem perda de dados | concluído no RDS real (o banco estava vazio; 12 migrations e o catálogo) |
| 4 | Papéis e permissões validados | concluído no RDS real: `verify.sql` aprovado e operações proibidas negadas |
| 5 | Login e autorização | validado (testes web e API, simulação) |
| 6 | Operações web persistindo | validado (testes e simulação); **pendente** em produção |
| 7 | App em modo servidor autenticando pela API | PWA publicado em `https://cadernetasef.duckdns.org/app/` apontando para a API de produção; APK gerado com a mesma URL e CORS conferido para as origens nativas; falta testar em aparelhos físicos |
| 8 | Mudanças do app na web e vice-versa | validado na simulação, nos dois sentidos, com navegador |
| 9 | Conflitos bloqueados, inclusive em concorrência | validado (`ConcurrentChangesTest`, 6 processos; constraint no banco) |
| 10 | Histórico e auditoria preservados | validado (triggers, permissões, auditoria com o autor vindo do token) |
| 11 | Lembretes e tarefas sem envios indevidos | validado localmente (interruptor, consentimento, envio único); envio real **pendente** |
| 12 | Integração de formulários preservada | validado (todos os testes existentes passam) |
| 13 | Testes relevantes aprovados | validado: 263 PHP, 49 mobile, 58 de layout (Chromium e WebKit) |
| 14 | Builds de produção | validado: web, app demo e servidor; APK de release assinado e verificado (26/10/2026). iOS nativo exige macOS e conta Apple: o iPhone usa o PWA |
| 15 | HTTPS e controles no ambiente implantado | concluído em 26/10/2026: `https://cadernetasef.duckdns.org` com certificado Let's Encrypt, redirecionamento HTTP→HTTPS, CSP, HSTS e demais cabeçalhos, API sem token recusada |
| 16 | Documentação de deploy, manutenção e restauração | concluída: este arquivo, [deployment-aws.md](deployment-aws.md), [database-production.md](database-production.md), [mobile-integration.md](mobile-integration.md), [operations.md](operations.md) |

## Antes da primeira implantação

- [ ] Domínio definido, com registro A para a EC2.
- [ ] Elastic IP aprovado e associado, ou aceito que o IP muda ao parar a instância.
- [ ] SG da EC2: 80 e 443 abertos; 22 restrito ou substituído por Session Manager.
- [ ] Regra `177.174.194.242/32` removida de `cadernetasef-db-access`.
- [ ] *Termination protection* (EC2) e *Deletion protection* (RDS) ligadas.
- [ ] Retenção dos backups automáticos do RDS em 7 dias ou mais.
- [ ] Senha mestre do RDS trocada depois do `caderneta-database-setup`.
- [ ] `APP_KEY` gerada pelo `provision.sh` guardada no cofre de senhas.
- [x] Migrations rodadas no RDS (aprovadas em 23/10/2026; banco vazio, sem snapshot necessário).
- [x] Conta administradora criada com `users:create`.
- [ ] Ao rodar `caderneta-database-setup` na EC2, as senhas dos papéis são trocadas; depois, apague `/root/caderneta-db.env`.

## Antes de usar com atendidos reais

- [ ] Serviço de e-mail aprovado, testado com `MAIL_ALWAYS_TO`; só então
      `MAIL_REMINDERS_ENABLED=true`.
- [ ] Equipe cadastrada com o perfil certo; visualizadores só onde necessário.
- [ ] Teste de restauração feito uma vez, a partir de um snapshot, num banco novo.
- [ ] Monitoramento externo de `https://<domínio>/up`.
- [ ] Apps nativos testados em aparelhos físicos, se forem distribuídos.

## Riscos que continuam

- **Um servidor e um banco Single-AZ.** Uma falha na EC2 ou na zona tira o
  sistema do ar até a restauração. É o custo aceito para o projeto; backups e
  snapshots evitam perda de dados, não a indisponibilidade.
- **Capacidade.** t3.small (créditos de CPU) e db.t4g.micro (cerca de 80
  conexões; a aplicação usa até 40) bastam para a equipe do projeto. A
  fotografia completa enviada ao app cresce com os anos e um dia vai pedir
  paginação.
- **`APP_KEY`.** Perdê-la torna ilegíveis CPF/CNPJ, observações e links; o índice
  de busca por documento depende dela.
- **Diferença de exposição.** Na web, o visualizador vê CPF/CNPJ e observações
  na ficha do atendido. No app, não recebe esses dados. Alinhar a web ao app
  reduziria a exposição, mas muda uma tela existente: é decisão do projeto.
- **LGPD.** Há medidas técnicas, sem afirmação de conformidade (que depende de
  avaliação jurídica e da universidade):
  - consentimento datado;
  - criptografia de campos;
  - menor privilégio no banco;
  - auditoria;
  - logs sem dados de conteúdo;
  - exposição mínima no app.
  
  Faltam política de retenção, exportação e exclusão a pedido do titular, e
  anonimização (RNF-007).
- **Scripts de implantação** foram conferidos (sintaxe, diagnóstico, backup e
  restauração na simulação), mas ainda não rodaram num Ubuntu 26.04 real. Pontos
  a observar na primeira vez:
  - versão do Node do Ubuntu;
  - `open_basedir` do pool do PHP-FPM, que pode ser afrouxado se algo legítimo
    for bloqueado.
- **Painel de impacto e relatórios na web** (M5) e **tela de usuários** ainda
  não existem.
