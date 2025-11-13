# Progress Log

## 2024-??-?? (Initial Survey)
- Identifiquei o plugin `wpmudev-plugin-test`, focado em integrar Google Drive ao admin do WordPress e em fornecer uma rotina de manutencao de posts.
- Bootstrap: `wpmudev-plugin-test.php` define constantes e carrega `WPMUDEV_PluginTest`, que aciona `core/class-loader.php` para registrar Admin Pages e Endpoints.
- Google Drive UI: `app/admin-pages/class-googledrive-settings.php` injeta assets React (`src/googledrive-page/main.jsx`) e passa dados/REST endpoints ao front-end.
- REST API: `app/endpoints/v1/class-googledrive-rest.php` contem os endpoints (save creds, auth, files, upload, download, create folder), mas a maioria esta com placeholders.
- Posts Maintenance ainda nao existe; teremos que criar admin page, rotinas em background, cron diario e WP-CLI.

## Backlog Oficial (QUESTIONS.md)
1. **Package Optimization**: reduzir o tamanho do zip final (provavelmente excluindo `node_modules`, assets fonte ou vendor redundante no build) sem quebrar o plugin.
2. **Google Drive Admin Interface**: completar a interface React (i18n, estados, credenciais, flows de upload/download/folders, notificacoes).
3. **REST save-credentials**: validar request, sanitizar e persistir com seguranca (bonus: criptografia) + permissao adequada.
4. **Google OAuth Flow**: gerar URL de autorizacao, lidar com callback, armazenar tokens (access/refresh/expiracao) e renovar automaticamente.
5. **Files List API**: conectar ao Drive, suportar paginacao/filtros e retornar dados formatados.
6. **File Upload API**: tratar uploads multipart com validacao, progresso/respostas adequadas.
7. **Posts Maintenance Page**: nova pagina "Posts Maintenance" com botao de scan, filtros por post type, processamento em background e cron diario.
8. **WP-CLI Command**: comando que executa o mesmo scan, com parametros, ajuda e exemplos.
9. **Dependency Management**: evitar conflitos de Composer (autoload isolado, prefixed, ou boundary claro) e documentar abordagem.
10. **Unit Tests**: cobertura para Posts Maintenance (scan, metas, edge cases, filtros, repetibilidade).

## Plano High-Level
- [x] **Fase 1 (Infra/Build):** Packaging otimizado (Grunt copia apenas runtime, vendor incluso, docs/testes fora), webpack configurado com externals e Babel preset declarado; `npm run build` concluindo e zip reduzido.
- [x] **Fase 2 (Drive Backend – concluída):**
    - [x] Desenhar armazenamento seguro para credenciais/tokens (opcoes separadas, criptografia opcional, autoload desligado).
    - [x] Implementar `wpmudev/v1/drive/save-credentials` (POST) com `manage_options`, nonce, sanitizacao e persistencia padronizada.
    - [x] Completar fluxo OAuth: endpoint `auth` (gera URL/redirect), callback validando `state`/`code`, salvando tokens e helper `ensure_valid_token()` com refresh.
    - [x] Construir rotas `files`, `upload`, `download`, `create-folder` com validacoes, paginacao e mensagens traduziveis usando Google Client.
    - [x] Extrair helper/servico compartilhado para inicializar Google Client e lidar com leitura/escrita de dados, retornando `WP_Error` consistentes.
- [x] **Fase 3 (Drive Front-End):**
    - [x] Implementar handlers de credenciais/autenticacao com `apiFetch`, nonces e notificacoes traduciveis.
    - [x] Construir UI de upload, criacao de pasta, listagem com paginacao e botoes de download/visualizacao.
    - [x] Exibir estados de carregamento, mensagens de sucesso/erro e atualizacao automatica apos operacoes.
- [ ] **Fase 4 (Posts Maintenance):** Criar servicos compartilhados para scan, pagina admin (#7), agendamentos e processamento assinc + comando WP-CLI (#8).
- [ ] **Fase 5 (Compat/Tests):** Implementar isolamento de dependencias (#9) e escrever testes PHPUnit (#10). Finalizar com revisao de documentacao e instrucoes de build.

## Assumptions & Questions
- Precisaremos de credenciais Google sandbox para validar OAuth end-to-end durante QA.
- Confirmar se e necessario criptografar as credenciais ou se basta usar `wp_hash_password()`/`openssl_encrypt` com chave definida.
- Definir como o background processing sera feito (WP Cron, Action Scheduler, WP Background Processing, etc.).

## Next Immediate Actions
1. Detalhar arquitetura do Posts Maintenance worker (cron + wp-cli compartilhando mesma service class).
2. Definir contratos/UX da página Posts Maintenance e como refletir progresso no admin.
